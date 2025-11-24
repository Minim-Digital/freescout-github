<?php

namespace Modules\Github\Support;

use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Modules\Github\Services\GithubApiClient;

class RepositoryCache
{
    private const CACHE_PREFIX = 'github_module.repositories.';
    private const THROTTLE_PREFIX = 'github_module.repositories.throttle.';
    private const THROTTLE_SECONDS = 30;

    /**
     * Retrieve repositories from cache, optionally hydrating from GitHub.
     *
     * @param  bool  $hydrate
     * @return array
     */
    public static function getRepositories(bool $hydrate = true): array
    {
        $tokenData = self::resolveToken();
        if ($tokenData['status'] !== 'success') {
            return $tokenData;
        }

        [$token, $hash] = $tokenData['data'];
        $cacheKey = self::cacheKey($hash);
        $cached = Cache::get($cacheKey);

        if (is_array($cached) && isset($cached['repositories'])) {
            return [
                'status' => 'success',
                'repositories' => $cached['repositories'],
                'source' => 'cache',
                'fetched_at' => $cached['fetched_at'] ?? null,
            ];
        }

        if (!$hydrate) {
            return [
                'status' => 'empty',
                'message' => 'Repository cache is empty.',
            ];
        }

        $fetchResult = self::fetchAndCache($token, $hash);
        if ($fetchResult['status'] === 'success') {
            return [
                'status' => 'success',
                'repositories' => $fetchResult['data'],
                'source' => 'api',
                'fetched_at' => $fetchResult['fetched_at'],
            ];
        }

        return $fetchResult;
    }

    /**
     * Search cached repositories (hydrating if required).
     *
     * @param  string  $query
     * @param  int  $limit
     * @return array
     */
    public static function search(string $query = '', int $limit = 20): array
    {
        $query = trim($query);
        $limit = $limit > 0 ? min($limit, 50) : 20;

        $repositoriesResult = self::getRepositories(true);
        if ($repositoriesResult['status'] !== 'success') {
            return $repositoriesResult;
        }

        $repositories = $repositoriesResult['repositories'];
        $source = $repositoriesResult['source'] ?? 'cache';
        $fetchedAt = $repositoriesResult['fetched_at'] ?? null;

        $filtered = self::filterRepositoriesByQuery($repositories, $query, $limit);
        $throttled = false;
        $retryAfter = null;

        if ($query !== '' && empty($filtered) && $source !== 'api') {
            $tokenData = self::resolveToken();
            if ($tokenData['status'] !== 'success') {
                return $tokenData;
            }

            [$token, $hash] = $tokenData['data'];
            $refreshResult = self::fetchAndCache($token, $hash);

            if ($refreshResult['status'] !== 'success') {
                if ($refreshResult['status'] === 'throttled') {
                    $throttled = true;
                    $retryAfter = $refreshResult['retry_after'] ?? self::THROTTLE_SECONDS;
                } else {
                    return $refreshResult;
                }
            } else {
                $source = 'api';
                $fetchedAt = $refreshResult['fetched_at'];
                $filtered = self::filterRepositoriesByQuery($refreshResult['data'], $query, $limit);
            }
        }

        return [
            'status' => 'success',
            'repositories' => $filtered,
            'source' => $source,
            'fetched_at' => $fetchedAt,
            'throttled' => $throttled,
            'retry_after' => $retryAfter,
        ];
    }

    /**
     * Clear cached repositories and throttle marker.
     *
     * @return void
     */
    public static function clear(): void
    {
        $tokenData = self::resolveToken(false);
        if ($tokenData['status'] !== 'success') {
            return;
        }

        [, $hash] = $tokenData['data'];
        Cache::forget(self::cacheKey($hash));
    }

    /**
     * Resolve stored GitHub token and hash.
     *
     * @param  bool  $requireToken
     * @return array
     */
    private static function resolveToken(bool $requireToken = true): array
    {
        $token = (string) \Option::get('github.token');
        if ($token === '') {
            if ($requireToken) {
                return [
                    'status' => 'error',
                    'message' => 'GitHub token is not configured.',
                ];
            }

            return [
                'status' => 'empty',
                'message' => 'GitHub token is not configured.',
            ];
        }

        $hash = substr(hash('sha256', $token), 0, 32);

        return [
            'status' => 'success',
            'data' => [$token, $hash],
        ];
    }

    /**
     * Fetch repositories from GitHub API and cache the results.
     *
     * @param  string  $token
     * @param  string  $hash
     * @return array
     */
    private static function fetchAndCache(string $token, string $hash): array
    {
        $throttle = self::checkThrottle($hash);
        if ($throttle['status'] === 'throttled') {
            return $throttle;
        }

        self::markApiCall($hash);

        $result = GithubApiClient::getRepositories();
        if (($result['status'] ?? null) !== 'success') {
            return [
                'status' => $result['status'] ?? 'error',
                'message' => $result['message'] ?? 'Failed to fetch repositories from GitHub.',
            ];
        }

        $repositories = self::sanitizeRepositories($result['data'] ?? []);
        $payload = [
            'token_hash' => $hash,
            'repositories' => $repositories,
            'fetched_at' => Carbon::now()->timestamp,
        ];

        Cache::forever(self::cacheKey($hash), $payload);

        return [
            'status' => 'success',
            'data' => $repositories,
            'fetched_at' => $payload['fetched_at'],
        ];
    }

    /**
     * Ensure repositories contain expected structure and filter unsupported ones.
     *
     * @param  array  $repositories
     * @return array
     */
    private static function sanitizeRepositories(array $repositories): array
    {
        return array_values(array_filter(array_map(function ($repo) {
            if (!is_array($repo) || empty($repo['full_name']) || empty($repo['name'])) {
                return null;
            }

            if (isset($repo['has_issues']) && $repo['has_issues'] === false) {
                return null;
            }

            return [
                'id' => $repo['id'] ?? null,
                'name' => $repo['name'],
                'full_name' => $repo['full_name'],
                'private' => $repo['private'] ?? false,
                'has_issues' => $repo['has_issues'] ?? true,
                'updated_at' => $repo['updated_at'] ?? null,
            ];
        }, $repositories)));
    }

    /**
     * Filter repositories by query and limit results.
     *
     * @param  array  $repositories
     * @param  string  $query
     * @param  int  $limit
     * @return array
     */
    private static function filterRepositoriesByQuery(array $repositories, string $query, int $limit): array
    {
        $filtered = $repositories;

        if ($query !== '') {
            $filtered = array_values(array_filter($repositories, function ($repo) use ($query) {
                $fullName = isset($repo['full_name']) ? $repo['full_name'] : '';
                $name = isset($repo['name']) ? $repo['name'] : '';

                return stripos($fullName, $query) !== false || stripos($name, $query) !== false;
            }));
        }

        if ($limit > 0) {
            $filtered = array_slice($filtered, 0, $limit);
        }

        return $filtered;
    }

    /**
     * Determine whether GitHub API throttling is active.
     *
     * @param  string  $hash
     * @return array
     */
    private static function checkThrottle(string $hash): array
    {
        $key = self::throttleKey($hash);
        $lastFetch = Cache::get($key);
        if (!$lastFetch) {
            return ['status' => 'ok'];
        }

        $secondsSince = Carbon::now()->timestamp - (int) $lastFetch;
        if ($secondsSince >= self::THROTTLE_SECONDS) {
            return ['status' => 'ok'];
        }

        return [
            'status' => 'throttled',
            'message' => 'GitHub repository list refreshed recently. Please wait before trying again.',
            'retry_after' => self::THROTTLE_SECONDS - $secondsSince,
        ];
    }

    /**
     * Store the timestamp of the last GitHub API call.
     *
     * @param  string  $hash
     * @return void
     */
    private static function markApiCall(string $hash): void
    {
        Cache::forever(self::throttleKey($hash), Carbon::now()->timestamp);
    }

    /**
     * Build cache key.
     *
     * @param  string  $hash
     * @return string
     */
    private static function cacheKey(string $hash): string
    {
        return self::CACHE_PREFIX . $hash;
    }

    /**
     * Build throttle key.
     *
     * @param  string  $hash
     * @return string
     */
    private static function throttleKey(string $hash): string
    {
        return self::THROTTLE_PREFIX . $hash;
    }
}


