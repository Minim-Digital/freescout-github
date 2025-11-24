/**
 * GitHub Module JavaScript
 * Following FreeScout Jira module pattern
 */

var GitHub = {
    config: {
        debounceDelay: 300,
        searchMinLength: 2,
        maxSearchResults: 10
    },
    cache: {
        repositories: null,
        loading: false,
        loadingCallbacks: [],
        repoSearchTimers: {},
        activeRepoRequests: {},
        lastThrottleNotice: 0,
        userMappings: null,
        userMappingsLoading: false
    },
    warningsShown: [], // Track warnings to prevent duplicates
    state: {
        currentRepository: null,
        labelMappingsSaving: false
    }
};

$(document).on('click', '.github-issue-action', githubHandleIssueAction);

function githubHandleIssueAction(e) {
    var $trigger = $(this);
    var action = $trigger.data('action');
    var issueId = $trigger.data('issue-id');

    if (!action || !issueId) {
        return;
    }

    e.preventDefault();

    if (action === 'unlink') {
        if (confirm('Are you sure you want to unlink this issue?')) {
            githubUnlinkIssue(issueId);
        }

        return;
    }

    if (action === 'refresh') {
        githubRefreshIssue(issueId);
    }
}

function githubGetCurrentTokenHash() {
    try {
        var $tokenField = $('#github_token');
        if ($tokenField.length === 0) {
            return null;
        }

        var token = $tokenField.val();
        if (!token) {
            return null;
        }

        return window.btoa(token).slice(-8);
    } catch (e) {
        return null;
    }
}

function githubPersistRepositoryCache(repositories) {
    if (!Array.isArray(repositories)) {
        return;
    }

    try {
        var cacheData = {
            repositories: repositories,
            token_hash: githubGetCurrentTokenHash(),
            stored_at: Date.now()
        };

        localStorage.setItem('github_repositories_cache', JSON.stringify(cacheData));
    } catch (e) {
        console.warn('GitHub: Failed to persist repository cache', e);
    }
}

function githubClearLocalRepositoryCache() {
    try {
        localStorage.removeItem('github_repositories_cache');
    } catch (e) {
        console.warn('GitHub: Failed to clear repository cache', e);
    }
}

function githubEnsureRepositoryCache(callback) {
    if (typeof callback !== 'function') {
        callback = null;
    }

    if (!Array.isArray(GitHub.cache.loadingCallbacks)) {
        GitHub.cache.loadingCallbacks = [];
    }

    if (GitHub.cache.repositories && GitHub.cache.repositories.length > 0) {
        if (callback) {
            callback(GitHub.cache.repositories);
        }
        return;
    }

    var cachedRepos = githubGetCachedRepositories();
    if (cachedRepos && cachedRepos.length > 0) {
        GitHub.cache.repositories = cachedRepos;
        if (callback) {
            callback(GitHub.cache.repositories);
        }
        return;
    }

    if (callback) {
        GitHub.cache.loadingCallbacks.push(callback);
    }

    if (GitHub.cache.loading) {
        return;
    }

    GitHub.cache.loading = true;

    githubLoadRepositories({
        skipCache: true,
        onSuccess: function(response) {
            if (response && Array.isArray(response.repositories)) {
                GitHub.cache.repositories = response.repositories;
            }
        },
        onComplete: function() {
            GitHub.cache.loading = false;

            var callbacks = Array.isArray(GitHub.cache.loadingCallbacks) ? GitHub.cache.loadingCallbacks.slice() : [];
            GitHub.cache.loadingCallbacks = [];

            var repos = GitHub.cache.repositories;
            if (!repos || repos.length === 0) {
                repos = githubGetCachedRepositories() || [];
                if (repos.length > 0) {
                    GitHub.cache.repositories = repos;
                }
            }

            $.each(callbacks, function(_, cb) {
                if (typeof cb === 'function') {
                    cb(repos);
                }
            });
        }
    });
}

function githubInitSettings() {
    $(document).ready(function() {
        // Show/hide OpenAI model dropdown based on AI service selection
        function toggleOpenAIModel() {
            var selectedService = $('#github_ai_service').val();
            
            if (selectedService === 'openai') {
                $('#openai_model_group').show();
            } else {
                $('#openai_model_group').hide();
            }
        }

        // Bind the change event for AI service dropdown
        $('#github_ai_service').on('change', toggleOpenAIModel);
        
        // Set initial state with a small delay to ensure DOM is fully loaded
        setTimeout(function() {
            toggleOpenAIModel();
        }, 100);
        
        // Also trigger on page load in case the value is pre-selected
        $(window).on('load', function() {
            toggleOpenAIModel();
        });

        // Test connection button
        $("#test-connection").click(function(e) {
            e.preventDefault();
            var button = $(this);
            var token = $('#github_token').val();

            if (!token) {
                showFloatingAlert('error', 'Please enter a GitHub token first');
                return;
            }

            button.button('loading');

            fsAjax({
                token: token
            }, 
            laroute.route('github.test_connection'), 
            function(response) {
                button.button('reset');
                if (isAjaxSuccess(response)) {
                    githubShowConnectionResult(response);
                    if (response.repositories) {
                        githubPersistRepositoryCache(response.repositories);
                        githubPopulateRepositories(response.repositories);
                    }
                } else {
                    githubShowConnectionResult(response);
                }
            }, true, function(response) {
                button.button('reset');
                showFloatingAlert('error', Lang.get("messages.ajax_error"));
            });
        });

        // Refresh repositories button
        $("#refresh-repositories").click(function(e) {
            e.preventDefault();
            githubRefreshRepositoryCache();
        });

        // Refresh allowed labels button
        $("#refresh-allowed-labels").click(function(e) {
            e.preventDefault();
            githubLoadAllowedLabels();
        });

        // Repository change handler
        $("#github_default_repository").change(function() {
            var repository = $(this).val();
            GitHub.state.currentRepository = repository || null;
            githubUpdateLabelMappingSection(repository);

            if (repository) {
                githubLoadLabelMappings(repository);
                githubLoadAllowedLabels();
            } else {
                githubResetLabelMappingsUI();
            }
        });

        // Initialize Select2 on allowed labels field
        var $allowedLabelsSelect = $('#github_allowed_labels');
        if ($allowedLabelsSelect.length > 0) {
            // Initialize with basic Select2 first
            $allowedLabelsSelect.select2({
                placeholder: $allowedLabelsSelect.attr('data-placeholder') || 'Select allowed labels...',
                allowClear: false,
                width: '100%'
            });
        }

        // Load allowed labels on page load if repository is already selected
        var defaultRepo = $("#github_default_repository").val();
        GitHub.state.currentRepository = defaultRepo || null;
        githubUpdateLabelMappingSection(defaultRepo);
        if (defaultRepo) {
            githubLoadAllowedLabels();
            githubLoadLabelMappings(defaultRepo);
        } else {
            githubResetLabelMappingsUI();
        }

        // Add label mapping
        $("#add-label-mapping").click(function(e) {
            e.preventDefault();
            githubAddLabelMappingRow();
        });

        // Persist label mappings
        $("#save-label-mappings").click(function(e) {
            e.preventDefault();
            githubSaveLabelMappings();
        });

        // Remove label mapping
        $(document).on('click', '.remove-mapping', function(e) {
            e.preventDefault();
            $(this).closest('.label-mapping-row').remove();
        });
    });
}

function githubInitModals() {
    $(document).ready(function() {
        // Create issue modal
        $('#github-create-issue-modal').on('show.bs.modal', function() {
            // Move modal to body to avoid z-index stacking context issues
            if ($(this).parent().get(0) !== document.body) {
                $(this).detach().appendTo('body');
            }

            $('#github-create-issue-form')[0].reset();
            
            // Initialize labels multiselect with Select2
            var labelsSelect = $('#github-issue-labels');
            if (!labelsSelect.hasClass('select2-hidden-accessible')) {
                labelsSelect.select2({
                    placeholder: 'Select labels...',
                    allowClear: true,
                    closeOnSelect: false,
                    width: '100%',
                    dropdownParent: $('#github-create-issue-modal'),
                    dropdownCssClass: 'github-select2-dropdown' // Custom class for z-index fix
                });
            } else {
                // Clear selection after form reset
                labelsSelect.val(null).trigger('change');
            }
            
            // Initialize watchers multiselect with Select2 and load user mappings
            var watchersSelect = $('#github-issue-watchers');
            if (!watchersSelect.hasClass('select2-hidden-accessible')) {
                watchersSelect.select2({
                    placeholder: 'Select watchers...',
                    allowClear: true,
                    closeOnSelect: false,
                    width: '100%',
                    dropdownParent: $('#github-create-issue-modal'),
                    dropdownCssClass: 'github-select2-dropdown'
                });
            }
            
            // Load user mappings and populate watchers dropdown
            githubLoadUserMappings(function(mappings) {
                githubPopulateWatchersDropdown(watchersSelect, mappings);
            });
            
            // Restore default repository after form reset
            githubEnsureRepositoryCache(function() {
                githubSetupRepositorySelect('#github-repository', '#github-create-issue-modal');

                setTimeout(function() {
                    if (GitHub.defaultRepository) {
                        githubSetDefaultRepository('#github-repository');
                    } else {
                        $('#github-repository').val(null).trigger('change');
                    }
                    
                    // Auto-generate content if fields are empty
                    if (!$('#github-issue-title').val() && !$('#github-issue-body').val()) {
                        githubGenerateIssueContent();
                    }
                }, 100);
            });
        });

        // Link issue modal
        $('#github-link-issue-modal').on('show.bs.modal', function() {
            // Move modal to body to avoid z-index stacking context issues
            if ($(this).parent().get(0) !== document.body) {
                $(this).detach().appendTo('body');
            }

            $('#github-link-issue-form')[0].reset();
            $('#github-search-results').hide();
            // Restore default repository after form reset
            githubEnsureRepositoryCache(function() {
                githubSetupRepositorySelect('#github-link-repository', '#github-link-issue-modal');

                setTimeout(function() {
                    if (GitHub.defaultRepository) {
                        githubSetDefaultRepository('#github-link-repository');
                    } else {
                        $('#github-link-repository').val(null).trigger('change');
                    }
                }, 10);
            });
        });

        // Repository change in create modal
        $(document).on('change', '#github-repository', function() {
            var repository = $(this).val();
            if (repository) {
                githubLoadRepositoryLabels(repository);
            }
        });

        
        // Manual generate content button
        $(document).on('click', '#github-generate-content-btn', function(e) {
            e.preventDefault();
            githubGenerateIssueContent();
        });

        // Issue search
        var searchTimeout;
        $(document).on('input', '#github-issue-search', function() {
            clearTimeout(searchTimeout);
            var query = $(this).val();
            var repository = $('#github-link-repository').val();
            
            searchTimeout = setTimeout(function() {
                if (repository && query.length >= GitHub.config.searchMinLength) {
                    githubSearchIssues(repository, query);
                } else {
                    $('#github-search-results').hide();
                }
            }, GitHub.config.debounceDelay);
        });

        // Select search result
        $(document).on('click', '.github-search-result-item', function() {
            var issueNumber = $(this).data('issue-number');
            $('#github-issue-number').val(issueNumber);
            $('#github-search-results').hide();
        });

        // Create issue button
        $(document).on('click', '#github-create-issue-btn', function(e) {
            e.preventDefault();
            var button = $(this);
            button.button('loading');

            var data = new FormData();
            var form = $('#github-create-issue-form').serializeArray();
            for (var field in form) {
                data.append(form[field].name, form[field].value);
            }
            data.append('conversation_id', getGlobalAttr('conversation_id'));

            fsAjax(
                data,
                laroute.route('github.create_issue'),
                function(response) {
                    button.button('reset');
                    if (isAjaxSuccess(response)) {
                        $('#github-create-issue-modal').modal('hide');
                        window.location.href = '';
                    } else {
                        githubShowAjaxError(response);
                    }
                }, true,
                function(xhr) {
                    button.button('reset');
                    githubShowAjaxError(xhr.responseJSON || {message: Lang.get("messages.ajax_error")});
                }, {
                    cache: false,
                    contentType: false,
                    processData: false
                }
            );
        });

        // Link issue button
        $(document).on('click', '#github-link-issue-btn', function(e) {
            e.preventDefault();
            var button = $(this);
            button.button('loading');

            var data = new FormData();
            var form = $('#github-link-issue-form').serializeArray();
            for (var field in form) {
                data.append(form[field].name, form[field].value);
            }
            data.append('conversation_id', getGlobalAttr('conversation_id'));

            fsAjax(
                data,
                laroute.route('github.link_issue'),
                function(response) {
                    button.button('reset');
                    if (isAjaxSuccess(response)) {
                        $('#github-link-issue-modal').modal('hide');
                        window.location.href = '';
                    } else {
                        githubShowAjaxError(response);
                    }
                }, true,
                function(xhr) {
                    button.button('reset');
                    githubShowAjaxError(xhr.responseJSON || {message: Lang.get("messages.ajax_error")});
                }, {
                    cache: false,
                    contentType: false,
                    processData: false
                }
            );
        });
    });
}

function githubLoadRepositories(options) {
    options = options || {};
    var skipCache = options.skipCache || false;
    var onSuccess = typeof options.onSuccess === 'function' ? options.onSuccess : null;
    var onComplete = typeof options.onComplete === 'function' ? options.onComplete : null;
    var $loadingDiv = $('#github-repositories-loading');
    var $refreshBtn = $('#refresh-repositories');

    $loadingDiv.show();
    if ($refreshBtn.length > 0) {
        $refreshBtn.find('.glyphicon').addClass('glyphicon-spin');
    }

    if (!skipCache) {
        var cachedRepos = githubGetCachedRepositories();
        if (cachedRepos && cachedRepos.length > 0) {
            githubPopulateRepositories(cachedRepos);
            $loadingDiv.hide();
            if ($refreshBtn.length > 0) {
                $refreshBtn.find('.glyphicon').removeClass('glyphicon-spin');
            }

            if (onSuccess) {
                onSuccess({
                    status: 'success',
                    source: 'local',
                    repositories: cachedRepos
                });
            }

            GitHub.cache.loading = false;

            if (onComplete) {
                onComplete();
            }

            return;
        }
    }

    GitHub.cache.loading = true;
    fsAjax({},
        laroute.route('github.repositories'),
        function(response) {
            if (response.status === 'success' && response.repositories) {
                githubPersistRepositoryCache(response.repositories);
                githubPopulateRepositories(response.repositories);

                if (onSuccess) {
                    onSuccess({
                        status: 'success',
                        source: response.source || 'api',
                        repositories: response.repositories
                    });
                }
            } else if (response.status === 'throttled') {
                showFloatingAlert('warning', response.message || 'Repository refresh is temporarily throttled. Please try again later.');
            } else {
                showFloatingAlert('error', 'Failed to load repositories: ' + (response.message || 'Unknown error'));
            }

            $loadingDiv.hide();
            if ($refreshBtn.length > 0) {
                $refreshBtn.find('.glyphicon').removeClass('glyphicon-spin');
            }

            GitHub.cache.loading = false;

            if (onComplete) {
                onComplete();
            }
        },
        true,
        function() {
            $loadingDiv.hide();
            if ($refreshBtn.length > 0) {
                $refreshBtn.find('.glyphicon').removeClass('glyphicon-spin');
            }
            showFloatingAlert('error', 'Failed to load repositories');

            GitHub.cache.loading = false;

            if (onComplete) {
                onComplete();
            }
        }
    );
}

function githubRefreshRepositoryCache() {
    var $loadingDiv = $('#github-repositories-loading');
    var $refreshBtn = $('#refresh-repositories');

    $loadingDiv.show();
    if ($refreshBtn.length > 0) {
        $refreshBtn.find('.glyphicon').addClass('glyphicon-spin');
    }

    fsAjax({},
        laroute.route('github.repositories.refresh'),
        function(response) {
            githubClearLocalRepositoryCache();
            GitHub.cache.repositories = null;

            if (response.status !== 'success') {
                showFloatingAlert('warning', response.message || 'Repository cache cleared.');
            }

            $loadingDiv.hide();
            if ($refreshBtn.length > 0) {
                $refreshBtn.find('.glyphicon').removeClass('glyphicon-spin');
            }

            githubLoadRepositories({ skipCache: true });
        },
        true,
        function() {
            $loadingDiv.hide();
            if ($refreshBtn.length > 0) {
                $refreshBtn.find('.glyphicon').removeClass('glyphicon-spin');
            }
            showFloatingAlert('error', 'Failed to refresh repositories');
        }
    );
}

function githubSetupRepositorySelect(selector, modalSelector) {
    var $select = $(selector);
    if ($select.length === 0) {
        return;
    }

    if ($select.hasClass('select2-hidden-accessible')) {
        return;
    }

    var placeholder = $select.data('placeholder') || Lang.get("messages.select_repository");
    $select.select2({
        placeholder: placeholder,
        allowClear: false,
        width: '100%',
        dropdownParent: $(modalSelector),
        dropdownCssClass: 'github-select2-dropdown',
        minimumInputLength: 0,
        ajax: {
            delay: 0,
            cache: true,
            url: laroute.route('github.repositories.search'),
            dataType: 'json',
            data: function(params) {
                return {
                    q: params.term || '',
                    limit: GitHub.config.maxSearchResults
                };
            },
            transport: function(params, success, failure) {
                var requestKey = selector;

                if (!GitHub.cache.repoSearchTimers) {
                    GitHub.cache.repoSearchTimers = {};
                }
                if (!GitHub.cache.activeRepoRequests) {
                    GitHub.cache.activeRepoRequests = {};
                }

                if (GitHub.cache.repoSearchTimers[requestKey]) {
                    clearTimeout(GitHub.cache.repoSearchTimers[requestKey]);
                }

                var timeoutId = setTimeout(function() {
                    delete GitHub.cache.repoSearchTimers[requestKey];

                    if (GitHub.cache.activeRepoRequests[requestKey]) {
                        GitHub.cache.activeRepoRequests[requestKey].abort();
                    }

                    var jqXHR = $.ajax(params)
                        .done(function(data) {
                            success(data);
                        })
                        .fail(function(xhr) {
                            if (xhr && xhr.statusText === 'abort') {
                                return;
                            }

                            var response = (xhr && xhr.responseJSON) ? xhr.responseJSON : {};
                            if (response.status === 'throttled') {
                                showFloatingAlert('warning', response.message || 'Repository search is temporarily throttled. Please try again later.');
                            } else {
                                showFloatingAlert('error', response.message || 'Failed to search repositories.');
                            }
                            failure(response);
                        })
                        .always(function() {
                            if (GitHub.cache.activeRepoRequests[requestKey] === jqXHR) {
                                delete GitHub.cache.activeRepoRequests[requestKey];
                            }
                        });

                    GitHub.cache.activeRepoRequests[requestKey] = jqXHR;
                }, GitHub.config.debounceDelay);

                GitHub.cache.repoSearchTimers[requestKey] = timeoutId;

                return {
                    abort: function() {
                        if (GitHub.cache.repoSearchTimers[requestKey]) {
                            clearTimeout(GitHub.cache.repoSearchTimers[requestKey]);
                            delete GitHub.cache.repoSearchTimers[requestKey];
                        }

                        if (GitHub.cache.activeRepoRequests[requestKey]) {
                            GitHub.cache.activeRepoRequests[requestKey].abort();
                            delete GitHub.cache.activeRepoRequests[requestKey];
                        }
                    }
                };
            },
            processResults: function(data) {
                if (data.status === 'success' && data.results) {
                    if (data.meta && data.meta.source === 'api') {
                        githubClearLocalRepositoryCache();
                        GitHub.cache.repositories = null;
                        if (!GitHub.cache.loading) {
                            githubLoadRepositories({ skipCache: true });
                        }
                    }

                    if (data.meta && data.meta.throttled) {
                        var now = Date.now();
                        var minInterval = GitHub.config.debounceDelay * 4;
                        if (!GitHub.cache.lastThrottleNotice || (now - GitHub.cache.lastThrottleNotice) > minInterval) {
                            var retryAfter = parseInt(data.meta.retry_after, 10);
                            if (isNaN(retryAfter) || retryAfter < 0) {
                                retryAfter = 30;
                            }
                            showFloatingAlert('info', 'Using cached repositories. You can retry in about ' + retryAfter + 's.');
                            GitHub.cache.lastThrottleNotice = now;
                        }
                    }

                    return {
                        results: $.map(data.results, function(repo) {
                            return {
                                id: repo.id,
                                text: repo.text || repo.id,
                                data: repo
                            };
                        })
                    };
                }

                if (data.status === 'throttled') {
                    showFloatingAlert('warning', data.message || 'Repository search is temporarily throttled. Please try again later.');
                }

                return { results: [] };
            }
        }
    });
}

function githubGetCachedRepositories() {
    try {
        var cached = localStorage.getItem('github_repositories_cache');
        if (!cached) return null;

        var cacheData = JSON.parse(cached);

        if (!cacheData || !Array.isArray(cacheData.repositories)) {
            localStorage.removeItem('github_repositories_cache');
            return null;
        }

        var currentTokenHash = githubGetCurrentTokenHash();
        if (currentTokenHash && cacheData.token_hash && cacheData.token_hash !== currentTokenHash) {
            localStorage.removeItem('github_repositories_cache');
            return null;
        }

        if (cacheData.repositories.length === 0) {
            return null;
        }

        return cacheData.repositories;
    } catch (e) {
        localStorage.removeItem('github_repositories_cache');
        return null;
    }
}

// Helper function to set default repository from DOM data
function githubSetDefaultRepository(selectId) {
    var select = $(selectId);
    if (select.length === 0) return;
    
    // Check for backend default first
    if (GitHub.defaultRepository && selectId !== '#github_default_repository') {
        if (select.find('option[value="' + GitHub.defaultRepository + '"]').length === 0) {
            var option = $('<option></option>')
                .attr('value', GitHub.defaultRepository)
                .text(GitHub.defaultRepository);
            select.append(option);
        }
        select.val(GitHub.defaultRepository).trigger('change');
        return;
    }
    
    // Check if there's already a selected option in the HTML (from Blade template)
    var defaultOption = select.find('option[selected]').first();
    if (defaultOption.length > 0) {
        select.val(defaultOption.val()).trigger('change');
    }
}

function githubPopulateRepositories(repositories) {
    // Cache repositories for reuse
    GitHub.cache.repositories = repositories;
    
    var selects = ['#github_default_repository', '#github-repository', '#github-link-repository'];
    
    $.each(selects, function(i, selectId) {
        var select = $(selectId);
        if (select.length === 0) return;
        
        var currentValue = select.val();
        var defaultOption = select.find('option[selected]').first();
        var defaultValue = defaultOption.length > 0 ? defaultOption.val() : '';
        
        // Use GitHub.defaultRepository if available and we're not in settings
        var backendDefault = (selectId !== '#github_default_repository' && GitHub.defaultRepository) ? GitHub.defaultRepository : '';

        var usesAjax = select.data('select2Search') === true || select.data('select2Search') === 'true';
        
        // For settings page, preserve any manually entered value
        if (selectId === '#github_default_repository' && currentValue) {
            // Remove all options except the placeholder and current value
            select.find('option').each(function() {
                if ($(this).val() !== '' && $(this).val() !== currentValue) {
                    $(this).remove();
                }
            });
        } else if (!usesAjax) {
            select.empty().append('<option value="">' + Lang.get("messages.select_repository") + '</option>');
        }
        
        // Determine which value should be selected (priority: current -> backend default -> template default)
        var valueToSelect = currentValue || backendDefault || defaultValue;

        if (usesAjax) {
            if (valueToSelect && select.find('option[value="' + valueToSelect + '"]').length === 0) {
                var ajaxOption = $('<option></option>')
                    .attr('value', valueToSelect)
                    .text(valueToSelect);
                select.append(ajaxOption);
            }

            if (!valueToSelect) {
                select.val('').trigger('change');
            } else {
                select.val(valueToSelect).trigger('change');
            }

            return;
        }
        
        // Add repositories that have issues enabled
        var foundRepository = false;
        $.each(repositories, function(i, repo) {
            if (repo.has_issues) {
                // Check if option already exists
                if (select.find('option[value="' + repo.full_name + '"]').length === 0) {
                    var selected = repo.full_name === valueToSelect ? 'selected' : '';
                    if (repo.full_name === valueToSelect) {
                        foundRepository = true;
                    }
                    select.append('<option value="' + repo.full_name + '" ' + selected + '>' + repo.full_name + '</option>');
                }
            }
        });
        
        // Set the value if we have a value to select
        if (valueToSelect) {
            select.val(valueToSelect);
        }
        
        // Show warning if repository not found
        if (valueToSelect && repositories.length > 0 && !foundRepository) {
            // Only show warning once per repository
            var warningKey = 'repo_not_found_' + valueToSelect;
            if (GitHub.warningsShown.indexOf(warningKey) === -1) {
                GitHub.warningsShown.push(warningKey);
                showFloatingAlert('warning', Lang.get("messages.current_repository_not_found") + ': ' + valueToSelect);
            }
        }
    });
}

function githubLoadAllowedLabels() {
    var repository = $("#github_default_repository").val();
    if (!repository) {
        return;
    }
    
    var $loadingDiv = $('#github-labels-loading');
    var $refreshBtn = $('#refresh-allowed-labels');
    var $select = $('#github_allowed_labels');
    
    // Show loading indicator
    $loadingDiv.show();
    $refreshBtn.find('.glyphicon').addClass('glyphicon-spin');
    
    // Get current allowed labels setting
    var currentAllowedLabels = [];
    try {
        var allowedLabelsJson = $('input[name="current_allowed_labels"]').val();
        if (allowedLabelsJson) {
            var parsed = JSON.parse(allowedLabelsJson);
            // Handle case where the stored value might be a JSON string or already an array
            if (Array.isArray(parsed)) {
                currentAllowedLabels = parsed;
            } else if (typeof parsed === 'string') {
                currentAllowedLabels = JSON.parse(parsed);
            }
        }
    } catch (e) {
        // Ignore parsing errors and fall back to defaults
    }
    
    // Use laroute to generate URL with encoded parameter
    var url = laroute.route('github.labels', { repository: repository });
    
    $.ajax({
        url: url,
        type: 'GET',
        success: function(response) {
            if (response.status === 'success' && response.data) {
                githubPopulateAllowedLabels(response.data, currentAllowedLabels);
            } else {
                console.error('Failed to load labels:', response.message);
                showFloatingAlert('error', 'Failed to load labels: ' + (response.message || 'Unknown error'));
            }
        },
        error: function(xhr) {
            console.error('Failed to load labels:', xhr);
            showFloatingAlert('error', 'Failed to load labels');
        },
        complete: function() {
            $loadingDiv.hide();
            $refreshBtn.find('.glyphicon').removeClass('glyphicon-spin');
        }
    });
}

function githubPopulateAllowedLabels(labels, currentAllowedLabels) {
    var $select = $('#github_allowed_labels');
    
    // Destroy existing Select2 if it exists
    if ($select.hasClass('select2-hidden-accessible')) {
        $select.select2('destroy');
    }
    
    $select.empty();
    
    // If no current allowed labels are set, select all by default
    var selectAll = currentAllowedLabels.length === 0;
    
    $.each(labels, function(i, label) {
        var isSelected = selectAll || currentAllowedLabels.indexOf(label.name) !== -1;
        var option = $('<option></option>')
            .attr('value', label.name)
            .text(label.name)
            .prop('selected', isSelected);
        
        // Add color styling if available
        if (label.color) {
            option.attr('data-color', '#' + label.color);
        }
        
        $select.append(option);
    });
    
    // Initialize Select2 with custom styling for labels
    $select.select2({
        placeholder: $select.attr('data-placeholder') || 'Select allowed labels...',
        allowClear: false,
        width: '100%',
        templateResult: function(label) {
            if (!label.id) return label.text;
            
            // Find the option element to get the color
            var $option = $select.find('option[value="' + label.id + '"]');
            var color = $option.attr('data-color');
            
            if (color) {
                var $result = $(
                    '<span style="display: flex; align-items: center;">' +
                        '<span style="display: inline-block; width: 12px; height: 12px; border-radius: 2px; margin-right: 8px; background-color: ' + color + ';"></span>' +
                        '<span>' + label.text + '</span>' +
                    '</span>'
                );
                return $result;
            }
            
            return label.text;
        },
        templateSelection: function(label) {
            if (!label.id) return label.text;
            
            // Find the option element to get the color
            var $option = $select.find('option[value="' + label.id + '"]');
            var color = $option.attr('data-color');
            
            if (color) {
                var $selection = $(
                    '<span style="display: flex; align-items: center;">' +
                        '<span style="display: inline-block; width: 10px; height: 10px; border-radius: 2px; margin-right: 6px; background-color: ' + color + ';"></span>' +
                        '<span>' + label.text + '</span>' +
                    '</span>'
                );
                return $selection;
            }
            
            return label.text;
        }
    });
    
}

function githubLoadRepositoryLabels(repository) {
    // Use laroute to generate URL with encoded parameter
    var url = laroute.route('github.labels', { repository: repository });
    
    $.ajax({
        url: url,
        type: 'GET',
        success: function(response) {
            if (response.status === 'success') {
                githubPopulateLabels(response.data);
            }
        },
        error: function(xhr) {
            console.error('Failed to load labels:', xhr);
        }
    });
}

function githubPopulateLabels(labels) {
    var select = $('#github-issue-labels');
    
    
    // Destroy existing Select2 if it exists
    if (select.hasClass('select2-hidden-accessible')) {
        select.select2('destroy');
    }
    
    select.empty();
    
    $.each(labels, function(i, label) {
        var option = $('<option></option>')
            .attr('value', label.name)
            .text(label.name);
        
        // Add color styling if available
        if (label.color) {
            option.attr('data-color', '#' + label.color);
        }
        
        select.append(option);
    });
    
    
    // Initialize Select2 for multiselect with custom styling for labels
    select.select2({
        placeholder: 'Select labels...',
        allowClear: true,
        closeOnSelect: false,
        width: '100%',
        dropdownParent: $('#github-create-issue-modal'), // Ensure dropdown renders in modal
        dropdownCssClass: 'github-select2-dropdown', // Custom class for z-index fix
        templateResult: function(label) {
            if (!label.id) return label.text;
            
            // Find the option element to get the color
            var $option = select.find('option[value="' + label.id + '"]');
            var color = $option.attr('data-color');
            
            if (color) {
                var $result = $(
                    '<span style="display: flex; align-items: center;">' +
                        '<span style="display: inline-block; width: 12px; height: 12px; border-radius: 2px; margin-right: 8px; background-color: ' + color + ';"></span>' +
                        '<span>' + label.text + '</span>' +
                    '</span>'
                );
                return $result;
            }
            
            return label.text;
        },
        templateSelection: function(label) {
            if (!label.id) return label.text;
            
            // Find the option element to get the color
            var $option = select.find('option[value="' + label.id + '"]');
            var color = $option.attr('data-color');
            
            if (color) {
                var $selection = $(
                    '<span style="display: flex; align-items: center;">' +
                        '<span style="display: inline-block; width: 10px; height: 10px; border-radius: 2px; margin-right: 6px; background-color: ' + color + ';"></span>' +
                        '<span>' + label.text + '</span>' +
                    '</span>'
                );
                return $selection;
            }
            
            return label.text;
        }
    });
    
}

/**
 * Load user mappings from server for watchers dropdown
 */
function githubLoadUserMappings(callback) {
    // Return cached mappings if available
    if (GitHub.cache.userMappings !== null) {
        if (typeof callback === 'function') {
            callback(GitHub.cache.userMappings);
        }
        return;
    }
    
    // Prevent duplicate requests
    if (GitHub.cache.userMappingsLoading) {
        return;
    }
    
    GitHub.cache.userMappingsLoading = true;
    
    $.ajax({
        url: laroute.route('github.user_mappings'),
        type: 'GET',
        success: function(response) {
            GitHub.cache.userMappingsLoading = false;
            
            if (response.status === 'success') {
                GitHub.cache.userMappings = response.data || [];
                if (typeof callback === 'function') {
                    callback(GitHub.cache.userMappings);
                }
            } else {
                console.error('Failed to load user mappings:', response.message);
                GitHub.cache.userMappings = [];
                if (typeof callback === 'function') {
                    callback([]);
                }
            }
        },
        error: function(xhr) {
            GitHub.cache.userMappingsLoading = false;
            console.error('Failed to load user mappings:', xhr);
            GitHub.cache.userMappings = [];
            if (typeof callback === 'function') {
                callback([]);
            }
        }
    });
}

/**
 * Populate watchers dropdown with user mappings
 * Defaults to selecting the current user if they have a mapping
 */
function githubPopulateWatchersDropdown(select, mappings) {
    if (!select || !select.length) {
        return;
    }
    
    // Destroy existing Select2 if it exists
    if (select.hasClass('select2-hidden-accessible')) {
        select.select2('destroy');
    }
    
    select.empty();
    
    var currentUserValue = null;
    
    $.each(mappings, function(i, mapping) {
        var option = $('<option></option>')
            .attr('value', mapping.github_username)
            .attr('data-user-id', mapping.user_id)
            .text(mapping.name + ' (@' + mapping.github_username + ')');
        
        select.append(option);
        
        // Track current user for default selection
        if (mapping.is_current_user) {
            currentUserValue = mapping.github_username;
        }
    });
    
    // Re-initialize Select2
    select.select2({
        placeholder: 'Select watchers...',
        allowClear: true,
        closeOnSelect: false,
        width: '100%',
        dropdownParent: $('#github-create-issue-modal'),
        dropdownCssClass: 'github-select2-dropdown'
    });
    
    // Default to selecting current user if they have a mapping
    if (currentUserValue) {
        select.val([currentUserValue]).trigger('change');
    }
}

function githubLoadLabelMappings(repository) {
    var $container = $('#label-mappings-container');
    if (!$container.length) {
        return;
    }

    if (!repository) {
        githubResetLabelMappingsUI();
        return;
    }

    githubSetLabelMappingStatus('muted', 'Loading label mappings…');
    githubToggleLabelMappingControls(true);
    $container.html('<p class="text-muted">Loading label mappings…</p>');

    $.ajax({
        url: laroute.route('github.label_mappings'),
        type: 'GET',
        data: { repository: repository },
        success: function(response) {
            githubToggleLabelMappingControls(false);

            if (response.status === 'success') {
                githubRenderLabelMappings(response.data || []);
                $('#save-label-mappings').prop('disabled', false);
                githubSetLabelMappingStatus('success', response.message || 'Label mappings loaded.');
            } else {
                $('#save-label-mappings').prop('disabled', true);
                githubSetLabelMappingStatus('danger', response.message || 'Failed to load label mappings.');
            }
        },
        error: function(xhr) {
            githubToggleLabelMappingControls(false);
            $('#save-label-mappings').prop('disabled', true);

            var errorMessage = (xhr.responseJSON && xhr.responseJSON.message) ? xhr.responseJSON.message : 'Failed to load label mappings.';
            githubSetLabelMappingStatus('danger', errorMessage);
            console.error('Failed to load label mappings:', xhr);
        }
    });
}

function githubRenderLabelMappings(mappings) {
    var $container = $('#label-mappings-container');
    $container.empty();

    if (!mappings || mappings.length === 0) {
        githubAddLabelMappingRow();
        return;
    }

    $.each(mappings, function(_, mapping) {
        githubAddLabelMappingRow(mapping);
    });
}

function githubAddLabelMappingRow(mapping) {
    mapping = mapping || {};

    var $container = $('#label-mappings-container');
    $container.find('.label-mapping-empty').remove();

    var freescoutTag = mapping.freescout_tag || '';
    var githubLabel = mapping.github_label || '';
    var threshold = typeof mapping.confidence_threshold !== 'undefined' && mapping.confidence_threshold !== null
        ? parseFloat(mapping.confidence_threshold)
        : '';

    var thresholdValue = threshold === '' || isNaN(threshold) ? '' : threshold;
    
    var html = '<div class="label-mapping-row">' +
        '<input type="text" class="form-control label-mapping-freescout" placeholder="FreeScout Tag" value="' + freescoutTag + '">' +
        '<span>→</span>' +
        '<input type="text" class="form-control label-mapping-github" placeholder="GitHub Label" value="' + githubLabel + '">' +
        '<input type="number" class="form-control label-mapping-threshold" placeholder="0.80" value="' + thresholdValue + '" min="0" max="1" step="0.01">' +
        '<button type="button" class="btn btn-danger btn-sm remove-mapping" title="Remove mapping">' +
            '<i class="glyphicon glyphicon-trash"></i>' +
        '</button>' +
    '</div>';

    $container.append(html);

    if (GitHub.state.currentRepository) {
        $('#save-label-mappings').prop('disabled', false);
    }
}

function githubCollectLabelMappings() {
    var rows = [];
    var hasError = false;

    $('.label-mapping-row').each(function() {
        var $row = $(this);
        var freescoutTag = ($row.find('.label-mapping-freescout').val() || '').trim();
        var githubLabel = ($row.find('.label-mapping-github').val() || '').trim();
        var thresholdRaw = $row.find('.label-mapping-threshold').val();
        var threshold = thresholdRaw === '' ? null : parseFloat(thresholdRaw);

        $row.removeClass('has-error');

        if (!freescoutTag && !githubLabel && threshold === null) {
            return;
        }

        if (!freescoutTag || !githubLabel) {
            hasError = true;
            $row.addClass('has-error');
            return;
        }

        rows.push({
            freescout_tag: freescoutTag,
            github_label: githubLabel,
            confidence_threshold: threshold === null || isNaN(threshold) ? 0.80 : threshold
        });
    });

    return {
        mappings: rows,
        hasError: hasError
    };
}

function githubSaveLabelMappings() {
    var repository = GitHub.state.currentRepository;
    if (!repository) {
        showFloatingAlert('warning', 'Select a repository before saving label mappings.');
        return;
    }

    var result = githubCollectLabelMappings();
    if (result.hasError) {
        githubSetLabelMappingStatus('danger', 'Complete all mapping rows before saving.');
        showFloatingAlert('error', 'Please complete all mapping rows before saving.');
        return;
    }

    githubSetLabelMappingsSaving(true);
    githubSetLabelMappingStatus('muted', 'Saving label mappings…');

    $.ajax({
        url: laroute.route('github.save_label_mappings'),
        type: 'POST',
        data: JSON.stringify({
            repository: repository,
            mappings: result.mappings
        }),
        contentType: 'application/json',
        headers: {
            'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content')
        },
        success: function(response) {
            githubSetLabelMappingsSaving(false);

            if (response.status === 'success') {
                githubRenderLabelMappings(response.data || []);
                githubSetLabelMappingStatus('success', response.message || 'Label mappings saved successfully.');
                showFloatingAlert('success', response.message || 'Label mappings saved.');
            } else {
                githubSetLabelMappingStatus('danger', response.message || 'Failed to save label mappings.');
                showFloatingAlert('error', response.message || 'Failed to save label mappings.');
            }
        },
        error: function(xhr) {
            githubSetLabelMappingsSaving(false);

            var message = (xhr.responseJSON && xhr.responseJSON.message) ? xhr.responseJSON.message : 'Failed to save label mappings.';
            githubSetLabelMappingStatus('danger', message);
            showFloatingAlert('error', message);
        }
    });
}

function githubSetLabelMappingsSaving(isSaving) {
    var $button = $('#save-label-mappings');
    if (!$button.length) {
        return;
    }

    if (isSaving) {
        GitHub.state.labelMappingsSaving = true;
        if (!$button.data('original-html')) {
            $button.data('original-html', $button.html());
        }
        $button.prop('disabled', true).html('<i class="glyphicon glyphicon-refresh glyphicon-spin"></i> Saving…');
    } else {
        GitHub.state.labelMappingsSaving = false;
        var originalHtml = $button.data('original-html');
        if (originalHtml) {
            $button.html(originalHtml);
        }
        if (GitHub.state.currentRepository) {
            $button.prop('disabled', false);
        }
    }
}

function githubSetLabelMappingStatus(tone, message) {
    var $status = $('#label-mapping-status');
    if (!$status.length) {
        return;
    }

    var toneClass = 'text-muted';
    if (tone === 'success') toneClass = 'text-success';
    if (tone === 'danger') toneClass = 'text-danger';
    if (tone === 'warning') toneClass = 'text-warning';

    $status
        .removeClass('text-muted text-success text-danger text-warning')
        .addClass(toneClass)
        .text(message || '');
}

function githubToggleLabelMappingControls(disabled) {
    $('#add-label-mapping').prop('disabled', disabled);
    if (disabled) {
        $('#save-label-mappings').prop('disabled', true);
    } else if (GitHub.state.currentRepository && !GitHub.state.labelMappingsSaving) {
        $('#save-label-mappings').prop('disabled', false);
    }
}

function githubResetLabelMappingsUI(message) {
    var $container = $('#label-mappings-container');
    if (!$container.length) {
        return;
    }

    var text = message || 'Select a repository to configure label mappings.';
    $container.html('<p class="text-muted label-mapping-empty">' + text + '</p>');
    githubSetLabelMappingStatus('muted', '');
    $('#save-label-mappings').prop('disabled', true);
    $('#add-label-mapping').prop('disabled', true);
}

function githubUpdateLabelMappingSection(repository) {
    var $section = $('#label-mapping-section');
    if (!$section.length) {
        return;
    }

    if (!repository) {
        $section.hide();
        githubResetLabelMappingsUI();
        return;
    }

    $section.show();
    $('#add-label-mapping').prop('disabled', false);
}

function githubSearchIssues(repository, query) {
    fsAjax({
        repository: repository,
        query: query,
        per_page: GitHub.config.maxSearchResults
    }, 
    laroute.route('github.search_issues'), 
    function(response) {
        if (isAjaxSuccess(response)) {
            githubDisplaySearchResults(response.issues);
        }
    }, true);
}

function githubDisplaySearchResults(issues) {
    var container = $('#github-search-results-list');
    container.empty();
    
    if (issues.length === 0) {
        container.html('<p class="text-muted">No issues found</p>');
    } else {
        $.each(issues, function(i, issue) {
            var badgeClass = issue.state === 'open' ? 'success' : 'secondary';
            var html = '<div class="github-search-result-item" data-issue-number="' + issue.number + '">' +
                '<div class="github-search-result-number">#' + issue.number + '</div>' +
                '<div class="github-search-result-title">' + issue.title + '</div>' +
                '<div class="github-search-result-meta">' +
                    '<span class="badge badge-' + badgeClass + '">' + issue.state + '</span>' +
                    ' • Updated ' + githubFormatDate(issue.updated_at) +
                '</div>' +
            '</div>';
            container.append(html);
        });
    }
    
    $('#github-search-results').show();
}

function githubGenerateIssueContent() {
    var conversationId = $('#github-create-issue-form input[name="conversation_id"]').val();
    
    // Fallback to global conversation ID if not found in form
    if (!conversationId) {
        conversationId = getGlobalAttr('conversation_id');
    }
    
    if (!conversationId) {
        showFloatingAlert('error', 'No conversation ID found');
        console.error('GitHub: Could not find conversation ID in form or global attributes');
        return;
    }
    
    
    // Show loading state
    var $titleField = $('#github-issue-title');
    var $bodyField = $('#github-issue-body');
    var $generateBtn = $('#github-generate-content-btn');
    
    $generateBtn.prop('disabled', true).find('i').removeClass('glyphicon-flash').addClass('glyphicon-refresh glyphicon-spin');
    
    // Show progress message for user feedback
    showFloatingAlert('info', 'Generating AI content...');
    
    $.ajax({
        timeout: 65000, // 65 second timeout to match backend
        url: laroute.route('github.generate_content'),
        type: 'POST',
        data: {
            conversation_id: conversationId,
            _token: $('meta[name="csrf-token"]').attr('content')
        },
        success: function(response) {
            
            if (response.status === 'success') {
                if (response.data.title && !$titleField.val()) {
                    $titleField.val(response.data.title);
                }
                if (response.data.body && !$bodyField.val()) {
                    $bodyField.val(response.data.body);
                }
                
                // Auto-select suggested labels if available
                if (response.data.suggested_labels && response.data.suggested_labels.length > 0) {
                    var labelsSelect = $('#github-issue-labels');
                    if (labelsSelect.hasClass('select2-hidden-accessible')) {
                        labelsSelect.val(response.data.suggested_labels).trigger('change');
                    }
                }
                
                showFloatingAlert('success', 'Content generated successfully');
            } else {
                // Handle error responses that come back as 200 but with error status
                var errorMessage = response.message || 'Failed to generate content';
                console.error('GitHub: Server returned error status:', response);
                showFloatingAlert('error', errorMessage);
            }
        },
        error: function(xhr) {
            console.error('GitHub: Generate content error:', xhr);
            var response = xhr.responseJSON || {};
            var errorMessage = 'Failed to generate content';
            
            // Handle cases where the response might be a JSON string instead of object
            if (typeof response === 'string') {
                try {
                    response = JSON.parse(response);
                } catch (e) {
                    console.error('GitHub: Failed to parse error response as JSON:', response);
                    response = {};
                }
            }
            
            // Extract the actual error message
            if (response.message) {
                errorMessage = response.message;
            } else if (xhr.responseText) {
                // Try to extract error from response text
                try {
                    var textResponse = JSON.parse(xhr.responseText);
                    if (textResponse.message) {
                        errorMessage = textResponse.message;
                    }
                } catch (e) {
                    // If it's not JSON, use the raw text (truncated)
                    errorMessage = xhr.responseText.length > 200 ? 
                        xhr.responseText.substring(0, 200) + '...' : 
                        xhr.responseText;
                }
            }
            
            // Add more detailed error info for debugging
            if (xhr.status === 400 && response.message) {
                // API errors (like OpenAI parameter issues)
                errorMessage = response.message;
            } else if (xhr.status === 500 && !response.message) {
                errorMessage = 'Server error occurred. Check server logs for details.';
            } else if (xhr.status === 422 && response.errors) {
                var errors = [];
                for (var field in response.errors) {
                    errors = errors.concat(response.errors[field]);
                }
                errorMessage = errors.join(', ');
            }
            
            // Log detailed error for debugging
            console.error('GitHub: Detailed error info:', {
                status: xhr.status,
                statusText: xhr.statusText,
                response: response,
                responseText: xhr.responseText,
                finalErrorMessage: errorMessage
            });
            
            showFloatingAlert('error', errorMessage);
        },
        complete: function() {
            $generateBtn.prop('disabled', false).find('i').removeClass('glyphicon-refresh glyphicon-spin').addClass('glyphicon-flash');
        }
    });
}

function githubFormatDate(dateString) {
    return new Date(dateString).toLocaleDateString();
}

function githubShowAjaxError(response) {
    var errorMessage = 'An error occurred';
    
    if (response.message) {
        errorMessage = response.message;
        
        // If there are validation errors, append them
        if (response.errors) {
            var errorDetails = [];
            for (var field in response.errors) {
                if (response.errors.hasOwnProperty(field)) {
                    var fieldErrors = response.errors[field];
                    if (Array.isArray(fieldErrors)) {
                        errorDetails = errorDetails.concat(fieldErrors);
                    }
                }
            }
            if (errorDetails.length > 0) {
                errorMessage += ':\n• ' + errorDetails.join('\n• ');
            }
        }
    } else if (response.errors) {
        // Handle case where there's no main message but there are errors
        var errors = [];
        for (var field in response.errors) {
            if (response.errors.hasOwnProperty(field)) {
                errors = errors.concat(response.errors[field]);
            }
        }
        errorMessage = errors.length > 0 ? errors.join('\n') : 'Validation failed';
    }
    
    showFloatingAlert('error', errorMessage);
}

function githubShowConnectionResult(response) {
    var $resultDiv = $('#github-connection-result');
    var $alert = $resultDiv.find('.alert');
    var $message = $resultDiv.find('.github-connection-message');
    
    // Remove existing classes
    $alert.removeClass('alert-success alert-danger alert-warning');
    
    if (response.status === 'success') {
        $alert.addClass('alert-success');
        var message = '<strong>' + Lang.get("messages.successful") + '</strong><br>';
        
        if (response.user) {
            message += Lang.get("messages.connected_as") + ': ' + response.user + '<br>';
        }
        if (response.permissions) {
            message += Lang.get("messages.permissions") + ': ' + response.permissions.join(', ') + '<br>';
        }
        if (response.rate_limit) {
            message += Lang.get("messages.api_calls_remaining") + ': ' + response.rate_limit.remaining + '/' + response.rate_limit.limit;
        }
        
        $message.html(message);
    } else {
        $alert.addClass('alert-danger');
        var errorMessage = '<strong>' + Lang.get("messages.error") + ':</strong> ' + (response.message || 'Unknown error');
        
        // Add troubleshooting hints based on error type
        if (response.message && response.message.includes('401')) {
            errorMessage += '<br><small>' + Lang.get("messages.check_token_valid") + '</small>';
        } else if (response.message && response.message.includes('404')) {
            errorMessage += '<br><small>' + Lang.get("messages.check_token_permissions") + '</small>';
        }
        
        $message.html(errorMessage);
    }
    
    $resultDiv.fadeIn();
    
    // Also show floating alert for quick feedback
    var alertType = response.status === 'success' ? 'success' : 'error';
    showFloatingAlert(alertType, response.message || 'Unknown response');
}

// Auto-initialize when DOM is ready
$(document).ready(function() {
    // Check if we're on a page with GitHub sidebar
    var $githubSidebar = $('.github-sidebar-block');
    if ($githubSidebar.length > 0) {
        // Get default repository from data attribute
        var defaultRepo = $githubSidebar.data('default-repository');
        if (defaultRepo) {
            GitHub.defaultRepository = defaultRepo;
        }
        
        // Initialize the GitHub modals functionality
        githubInitModals();
        
        // Only load repositories if we don't have cached ones and we're actually going to use them
        // Skip auto-loading on conversation pages since we already have default repository
        if (!GitHub.cache.repositories) {
            // Try localStorage cache first
            var cachedRepos = githubGetCachedRepositories();
            if (cachedRepos) {
                GitHub.cache.repositories = cachedRepos;
            }
            // Don't auto-load repositories on conversation pages - only load when modals are opened
        }
        
        // Auto-refresh issues when conversation is opened (with intelligent caching)
        githubAutoRefreshOnLoad();
    }
    
    // Check if we're on the settings page
    if ($('#github_default_repository').length > 0) {
        githubInitSettings();
        
        // Auto-load repositories if token exists
        if ($('#github_token').val()) {
            // Try to load from cache first
            var cachedRepos = githubGetCachedRepositories();
            if (cachedRepos) {
                githubPopulateRepositories(cachedRepos);
            } else {
                githubLoadRepositories();
            }
        }
    }
});

function githubUnlinkIssue(issueId) {
    $.ajax({
        url: laroute.route('github.unlink_issue'),
        type: 'POST',
        data: {
            _token: $('meta[name="csrf-token"]').attr('content'),
            conversation_id: $('#github-create-issue-form input[name="conversation_id"]').val(),
            issue_id: issueId
        },
        success: function(response) {
            if (response.status === 'success') {
                showFloatingAlert('success', response.message);
                window.location.reload();
            } else {
                showFloatingAlert('error', response.message);
            }
        }
    });
}

function githubRefreshIssue(issueId) {
    var $refreshButton = $('[data-issue-id="' + issueId + '"]').find('.glyphicon-refresh');
    $refreshButton.addClass('glyphicon-spin');
    
    var url = laroute.route('github.refresh_issue', {id: issueId});
    
    $.ajax({
        url: url,
        type: 'POST',
        data: {
            _token: $('meta[name="csrf-token"]').attr('content')
        },
        success: function(response) {
            if (response.status === 'success') {
                showFloatingAlert('success', 'Issue refreshed successfully');
                // Reload the page to show updated issue data
                window.location.reload();
            } else {
                showFloatingAlert('error', response.message || 'Failed to refresh issue');
            }
        },
        error: function(xhr) {
            var response = xhr.responseJSON || {};
            var errorMessage = response.message || 'Failed to refresh issue';
            
            if (xhr.status === 403) {
                errorMessage = 'You do not have permission to refresh this issue';
            } else if (xhr.status === 404) {
                errorMessage = 'Issue not found';
            }
            
            showFloatingAlert('error', errorMessage);
        },
        complete: function() {
            $refreshButton.removeClass('glyphicon-spin');
        }
    });
}

/**
 * Refresh all issues for the current conversation with intelligent caching
 */
function githubRefreshConversationIssues() {
    var conversationId = getGlobalAttr('conversation_id');
    if (!conversationId) {
        console.warn('GitHub: No conversation ID found for auto-refresh');
        return;
    }
    
    var $refreshButtons = $('.github-issue-action[data-action="refresh"] .glyphicon-refresh');
    $refreshButtons.addClass('glyphicon-spin');
    
    $.ajax({
        url: laroute.route('github.refresh_conversation_issues'),
        type: 'POST',
        data: {
            conversation_id: conversationId,
            _token: $('meta[name="csrf-token"]').attr('content')
        },
        success: function(response) {
            if (response.status === 'success') {
                // Only show success message if manually triggered
                var isManualRefresh = arguments.callee.caller && arguments.callee.caller.name === 'githubManualRefreshConversation';
                if (isManualRefresh) {
                    showFloatingAlert('success', 'All issues refreshed successfully');
                }
                
                // Check if any issues were actually updated
                var needsReload = false;
                if (response.data && response.data.length > 0) {
                    // Simple check: if we have issues and they might have been updated
                    needsReload = true;
                }
                
                if (needsReload) {
                    // Reload to show updated data
                    window.location.reload();
                }
            } else {
                showFloatingAlert('error', response.message || 'Failed to refresh issues');
            }
        },
        error: function(xhr) {
            var response = xhr.responseJSON || {};
            var errorMessage = response.message || 'Failed to refresh issues';
            
            if (xhr.status === 403) {
                errorMessage = 'You do not have permission to refresh issues';
            }
            
            showFloatingAlert('error', errorMessage);
        },
        complete: function() {
            $refreshButtons.removeClass('glyphicon-spin');
        }
    });
}

/**
 * Manual refresh function for explicit user action
 */
function githubManualRefreshConversation() {
    githubRefreshConversationIssues();
}

/**
 * Auto-refresh issues when conversation is loaded
 * Uses intelligent caching to prevent excessive API calls
 */
function githubAutoRefreshOnLoad() {
    var conversationId = getGlobalAttr('conversation_id');
    if (!conversationId) {
        return;
    }
    
    // Check if there are any GitHub issues in the sidebar
    var $githubIssues = $('.github-issue-item');
    if ($githubIssues.length === 0) {
        return; // No issues to refresh
    }
    
    // Check local storage to see when we last auto-refreshed this conversation
    var lastAutoRefreshKey = 'github_auto_refresh_conv_' + conversationId;
    var lastAutoRefresh = localStorage.getItem(lastAutoRefreshKey);
    var now = Date.now();
    var fiveMinutes = 5 * 60 * 1000; // 5 minutes in milliseconds
    
    if (lastAutoRefresh && (now - parseInt(lastAutoRefresh)) < fiveMinutes) {
        // Skip auto-refresh if we've done it recently
        return;
    }
    
    // Perform silent refresh (no success message)
    githubRefreshConversationIssues();
    
    // Update the last auto-refresh timestamp
    localStorage.setItem(lastAutoRefreshKey, now.toString());
    
    // Clean up old timestamps (keep only last 50 conversations)
    try {
        var keysToClean = [];
        for (var key in localStorage) {
            if (key.startsWith('github_auto_refresh_conv_')) {
                keysToClean.push({
                    key: key,
                    timestamp: parseInt(localStorage.getItem(key) || '0')
                });
            }
        }
        
        // Sort by timestamp and keep only the most recent 50
        keysToClean.sort(function(a, b) { return b.timestamp - a.timestamp; });
        if (keysToClean.length > 50) {
            for (var i = 50; i < keysToClean.length; i++) {
                localStorage.removeItem(keysToClean[i].key);
            }
        }
    } catch (e) {
        // Ignore localStorage errors
        console.warn('GitHub: Failed to clean up localStorage:', e);
    }
}