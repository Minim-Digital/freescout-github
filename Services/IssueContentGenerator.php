<?php

namespace Modules\Github\Services;

use App\Conversation;
use Illuminate\Support\Str;

class IssueContentGenerator
{
    /**
     * Generate issue title and body from conversation
     */
    public function generateContent(Conversation $conversation, $availableLabels = [])
    {
        // Single, clean log entry
        \Helper::log('github_ai', 'Content generation started for conversation #' . $conversation->id . ' with ' . count($availableLabels) . ' available labels');

        $aiService = \Option::get('github.ai_service');
        $aiApiKey = \Option::get('github.ai_api_key');

        if (!$aiApiKey || empty($aiService)) {
            \Helper::log('github_ai', 'No AI service configured, using manual generation');
            return $this->generateManualContent($conversation);
        }

        try {
            switch ($aiService) {
                case 'openai':
                    \Helper::log('github_ai', 'Using OpenAI service for conversation #' . $conversation->id);
                    return $this->generateWithOpenAI($conversation, $aiApiKey, $availableLabels);
                case 'claude':
                    \Helper::log('github_ai', 'Using Claude service for conversation #' . $conversation->id);
                    return $this->generateWithClaude($conversation, $aiApiKey, $availableLabels);
                default:
                    \Helper::log('github_ai', 'Unknown AI service (' . $aiService . '), using manual generation');
                    return $this->generateManualContent($conversation);
            }
        } catch (\Exception $e) {
            \Helper::log('github_ai', 'ERROR: ' . $e->getMessage());
            \Helper::logException($e, '[GitHub] AI Content Generation Error');
            
            // Re-throw API errors so frontend can display them properly
            if (strpos($e->getMessage(), 'Failed to generate content:') === 0) {
                throw $e;
            }
            
            return $this->generateManualContent($conversation);
        }
    }

    /**
     * Generate content using OpenAI API
     */
        private function generateWithOpenAI(Conversation $conversation, $apiKey, $availableLabels = [])
    {
        $conversationText = $this->extractConversationText($conversation);
        $prompt = $this->buildPrompt($conversationText, $conversation, $availableLabels);
        
        // Sanitize prompt for GPT-5 Mini strict UTF-8 compliance
        $prompt = $this->sanitizeForGPT5Mini($prompt);
        
        \Helper::log('github_ai', 'OpenAI request prepared: ' . strlen($prompt) . ' chars, ' . count($availableLabels) . ' labels');

        // Determine SSL settings based on environment
        $isLocalDev = in_array(config('app.env'), ['local', 'dev', 'development']) || 
                      strpos(config('app.url'), '.local') !== false ||
                      strpos(config('app.url'), 'localhost') !== false;

        $curl = curl_init();
        
        // Determine if this is a GPT-5 model
        $model = \Option::get('github.openai_model', 'gpt-5-mini');
        $isGPT5 = (strpos($model, 'gpt-5') !== false);
        
        // Determine timeout based on model - GPT-5 models are slower
        $timeout = $isGPT5 ? 60 : 30;
        
        // Use different API endpoints for GPT-5 vs older models
        $apiUrl = $isGPT5 ? 'https://api.openai.com/v1/responses' : 'https://api.openai.com/v1/chat/completions';
        
        curl_setopt_array($curl, [
            CURLOPT_URL => $apiUrl,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_SSL_VERIFYPEER => !$isLocalDev,
            CURLOPT_SSL_VERIFYHOST => $isLocalDev ? 0 : 2,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $apiKey,
                'Content-Type: application/json; charset=utf-8'
            ],
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $this->prepareOpenAIPayload($prompt, $isGPT5)
        ]);

        $response = curl_exec($curl);
        $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $error = curl_error($curl);
        $errno = curl_errno($curl);
        $info = curl_getinfo($curl);
        curl_close($curl);

        // Handle API errors
        if ($httpCode !== 200) {
            $errorMessage = 'OpenAI API Error: HTTP ' . $httpCode;
            
            // Add more specific error handling for HTTP 0
            if ($httpCode === 0) {
                if ($error) {
                    $errorMessage .= ' - Connection failed: ' . $error;
                } else {
                    $errorMessage .= ' - Network timeout or connection refused. This is usually temporary - please try again.';
                }
            } else {
                // Try to parse the error response for other HTTP codes
                if ($response) {
                    $errorData = json_decode($response, true);
                    if ($errorData && isset($errorData['error']['message'])) {
                        $errorMessage .= ' - ' . $errorData['error']['message'];
                    }
                }
            }
            
            \Helper::log('github_ai', 'ERROR: ' . $errorMessage);
            throw new \Exception('Failed to generate content: ' . $errorMessage);
        }

        if ($httpCode === 200) {
            // Ensure response is properly UTF-8 encoded
            $response = mb_convert_encoding($response, 'UTF-8', 'UTF-8');
            $data = json_decode($response, true);
            
            if (json_last_error() !== JSON_ERROR_NONE) {
                $jsonError = json_last_error_msg();
                \Helper::log('github_ai', 'ERROR: Invalid JSON response - ' . $jsonError);
                throw new \Exception('Failed to generate content: Invalid JSON response from OpenAI API - ' . $jsonError);
            }
            
            // Log token usage summary
            if (isset($data['usage'])) {
                $usage = $data['usage'];
                $finishReason = isset($data['choices'][0]['finish_reason']) ? $data['choices'][0]['finish_reason'] : 'unknown';
                \Helper::log('github_ai', 'OpenAI response: ' . ($usage['prompt_tokens'] ?? 0) . ' prompt + ' . ($usage['completion_tokens'] ?? 0) . ' completion = ' . ($usage['total_tokens'] ?? 0) . ' total tokens, finish: ' . $finishReason);
            }
            
            // Handle different response formats for GPT-5 vs older models
            $contentString = null;
            $finishReason = 'unknown';
            
            if ($isGPT5) {
                // GPT-5 Responses API format - debug the structure
                \Helper::log('github_ai', 'GPT-5 response structure: ' . json_encode(array_keys($data)));
                
                if (isset($data['output']) && is_array($data['output']) && !empty($data['output'])) {
                    \Helper::log('github_ai', 'Output array length: ' . count($data['output']));
                    
                    // Loop through all output items to find the message content
                    foreach ($data['output'] as $index => $outputItem) {
                        \Helper::log('github_ai', 'Output[' . $index . '] keys: ' . json_encode(array_keys($outputItem)));
                        \Helper::log('github_ai', 'Output[' . $index . '] type: ' . ($outputItem['type'] ?? 'unknown'));
                        
                        // Look for message type with content
                        if (isset($outputItem['type']) && $outputItem['type'] === 'message' && isset($outputItem['content'])) {
                            \Helper::log('github_ai', 'Found message type at index ' . $index);
                            
                            if (is_array($outputItem['content']) && !empty($outputItem['content'])) {
                                \Helper::log('github_ai', 'Content array length: ' . count($outputItem['content']));
                                
                                if (isset($outputItem['content'][0]['text'])) {
                                    $contentString = $outputItem['content'][0]['text'];
                                    $finishReason = isset($outputItem['status']) ? $outputItem['status'] : 'completed';
                                    \Helper::log('github_ai', 'Found content at output[' . $index . '].content[0].text');
                                    break; // Found content, exit loop
                                } else {
                                    \Helper::log('github_ai', 'Content[0] structure: ' . json_encode($outputItem['content'][0]));
                                }
                            }
                        } else {
                            \Helper::log('github_ai', 'Output[' . $index . '] full structure: ' . json_encode($outputItem));
                        }
                    }
                }
            } elseif (!$isGPT5 && isset($data['choices'][0]['message']['content'])) {
                // GPT-4/3.5 Chat Completions API format
                $contentString = $data['choices'][0]['message']['content'];
                $finishReason = isset($data['choices'][0]['finish_reason']) ? $data['choices'][0]['finish_reason'] : 'unknown';
            }
            
            if ($contentString !== null) {
                
                // Check for empty content due to token limits
                if (empty($contentString)) {
                    if ($finishReason === 'length') {
                        \Helper::log('github_ai', 'ERROR: Response cut off due to token limit');
                        throw new \Exception('Failed to generate content: OpenAI response was cut off due to token limit. The prompt may be too long or max_completion_tokens too small.');
                    } else {
                        \Helper::log('github_ai', 'ERROR: Empty content, finish_reason: ' . $finishReason);
                        throw new \Exception('Failed to generate content: OpenAI returned empty content (finish_reason: ' . $finishReason . ')');
                    }
                }
                
                // Ensure content string is UTF-8
                $contentString = mb_convert_encoding($contentString, 'UTF-8', 'UTF-8');
                
                $content = json_decode($contentString, true);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    $jsonError = json_last_error_msg();
                    \Helper::log('github_ai', 'ERROR: Invalid JSON content - ' . $jsonError);
                    throw new \Exception('Failed to generate content: Invalid JSON content from OpenAI API - ' . $jsonError);
                }
                
                if ($content && isset($content['title'], $content['body'])) {
                    // Ensure title and body are UTF-8
                    if (isset($content['title'])) {
                        $content['title'] = mb_convert_encoding($content['title'], 'UTF-8', 'UTF-8');
                    }
                    if (isset($content['body'])) {
                        $content['body'] = mb_convert_encoding($content['body'], 'UTF-8', 'UTF-8');
                    }
                    
                    $labelCount = isset($content['suggested_labels']) ? count($content['suggested_labels']) : 0;
                    \Helper::log('github_ai', 'SUCCESS: Generated title (' . strlen($content['title']) . ' chars), body (' . strlen($content['body']) . ' chars), ' . $labelCount . ' labels');
                    
                    // Filter suggested labels based on allowed labels setting
                    $content = $this->filterSuggestedLabels($content);
                    
                    // Post-process to inject conversation JSON
                    return $this->injectConversationContext($content, $conversation);
                } else {
                    \Helper::log('github_ai', 'ERROR: Missing required fields (title/body)');
                    throw new \Exception('Failed to generate content: OpenAI response missing required title or body fields');
                }
            } else {
                \Helper::log('github_ai', 'ERROR: Response missing content field');
                throw new \Exception('Failed to generate content: OpenAI response missing content field');
            }
        }

        // This should never be reached due to the error handling above
        throw new \Exception('Failed to generate content: Unexpected OpenAI API response');
    }

    /**
     * Prepare OpenAI API payload with extensive debugging
     */
    private function prepareOpenAIPayload($prompt, $isGPT5 = false)
    {
        $model = \Option::get('github.openai_model', 'gpt-5-mini');

        if ($isGPT5) {
            // GPT-5 uses the new Responses API format
            $payload = [
                'model' => $model,
                'instructions' => 'You are a helpful assistant that creates GitHub issues from customer support conversations. Always respond with valid JSON containing "title", "body", and "suggested_labels" fields.',
                'input' => [
                    [
                        'role' => 'user',
                        'content' => $prompt
                    ]
                ],
                'max_output_tokens' => 2000,
                'reasoning' => [
                    'effort' => 'low'  // Use low reasoning effort for faster responses
                ],
                'text' => [
                    'verbosity' => 'low'  // Keep responses concise for faster processing
                ]
            ];
        } else {
            // GPT-4/3.5 use the chat completions API format
            $payload = [
                'model' => $model,
                'messages' => [
                    [
                        'role' => 'system',
                        'content' => 'You are a helpful assistant that creates GitHub issues from customer support conversations. Always respond with valid JSON containing "title" and "body" fields.'
                    ],
                    [
                        'role' => 'user',
                        'content' => $prompt
                    ]
                ],
                'max_tokens' => 1500,
                'temperature' => 0.7
            ];
        }

        // Sanitize the entire payload
        $sanitizedPayload = $this->sanitizeDataForGPT5Mini($payload);

        // Try JSON encoding with fallback
        $jsonPayload = json_encode($sanitizedPayload, JSON_UNESCAPED_UNICODE);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            // Try without JSON_UNESCAPED_UNICODE
            $jsonPayload = json_encode($sanitizedPayload);
            if (json_last_error() !== JSON_ERROR_NONE) {
                // Try with Helper::jsonEncodeSafe as fallback
                $jsonPayload = \Helper::jsonEncodeSafe($sanitizedPayload);
            }
        }

        return $jsonPayload;
    }

    /**
     * Comprehensive UTF-8 sanitizer for GPT-5 Mini compatibility
     * GPT-5 Mini is much stricter about UTF-8 compliance than GPT-3.5 Turbo
     */
    private function sanitizeForGPT5Mini($text)
    {
        if (empty($text)) {
            return $text;
        }
        
        // Step 1: Convert to valid UTF-8, replacing invalid sequences
        $text = mb_convert_encoding($text, 'UTF-8', 'UTF-8');
        
        // Step 2: Remove control characters (except tab, newline, carriage return)
        $text = preg_replace('/[[:cntrl:]]/', '', $text);
        // But keep essential whitespace
        $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $text);
        
        // Step 3: Normalize line endings
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        
        // Step 4: Remove BOM (Byte Order Mark) if present
        $text = str_replace("\xEF\xBB\xBF", '', $text);
        
        // Step 5: Remove any remaining null bytes
        $text = str_replace("\0", '', $text);
        
        // Step 6: Ensure the result is still valid UTF-8
        if (!mb_check_encoding($text, 'UTF-8')) {
            // Fallback: force conversion and remove problematic characters
            $text = mb_convert_encoding($text, 'UTF-8', 'auto');
            $text = filter_var($text, FILTER_UNSAFE_RAW, FILTER_FLAG_STRIP_LOW | FILTER_FLAG_STRIP_HIGH);
        }
        
        return $text;
    }
    
    /**
     * Recursively sanitize arrays and objects for GPT-5 Mini
     */
    private function sanitizeDataForGPT5Mini($data)
    {
        if (is_string($data)) {
            return $this->sanitizeForGPT5Mini($data);
        } elseif (is_array($data)) {
            return array_map([$this, 'sanitizeDataForGPT5Mini'], $data);
        } elseif (is_object($data)) {
            foreach ($data as $key => $value) {
                $data->$key = $this->sanitizeDataForGPT5Mini($value);
            }
            return $data;
        }
        
        return $data;
    }

    /**
     * Generate content using Claude API
     */
    private function generateWithClaude(Conversation $conversation, $apiKey, $availableLabels = [])
    {
        $conversationText = $this->extractConversationText($conversation);

        $prompt = $this->buildPrompt($conversationText, $conversation, $availableLabels);

        \Helper::log('github_ai', 'Claude request prepared: ' . strlen($prompt) . ' chars, ' . count($availableLabels) . ' labels');

        // Determine SSL settings based on environment
        $isLocalDev = in_array(config('app.env'), ['local', 'dev', 'development']) ||
                      strpos(config('app.url'), '.local') !== false ||
                      strpos(config('app.url'), 'localhost') !== false;

        $curl = curl_init();
        curl_setopt_array($curl, [
            CURLOPT_URL => 'https://api.anthropic.com/v1/messages',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_SSL_VERIFYPEER => !$isLocalDev,
            CURLOPT_SSL_VERIFYHOST => $isLocalDev ? 0 : 2,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTPHEADER => [
                'x-api-key: ' . $apiKey,
                'Content-Type: application/json',
                'anthropic-version: 2023-06-01'
            ],
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => \Helper::jsonEncodeSafe([
                'model' => 'claude-haiku-4-5-20251001',
                'max_tokens' => 1500,
                'messages' => [
                    [
                        'role' => 'user',
                        'content' => $prompt
                    ]
                ]
            ])
        ]);

        $response = curl_exec($curl);
        $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $error = curl_error($curl);
        curl_close($curl);

        // Handle connection/curl errors
        if ($httpCode === 0) {
            $errorMessage = 'Claude API connection failed';
            if ($error) {
                $errorMessage .= ': ' . $error;
            }
            \Helper::log('github_ai', 'ERROR: ' . $errorMessage);
            throw new \Exception('Failed to generate content: ' . $errorMessage);
        }

        // Handle API errors
        if ($httpCode !== 200) {
            $errorMessage = 'Claude API Error: HTTP ' . $httpCode;
            if ($response) {
                $errorData = json_decode($response, true);
                if ($errorData && isset($errorData['error']['message'])) {
                    $errorMessage .= ' - ' . $errorData['error']['message'];
                }
            }
            \Helper::log('github_ai', 'ERROR: ' . $errorMessage);
            throw new \Exception('Failed to generate content: ' . $errorMessage);
        }

        $data = json_decode($response, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            \Helper::log('github_ai', 'ERROR: Invalid JSON response from Claude - ' . json_last_error_msg());
            throw new \Exception('Failed to generate content: Invalid JSON response from Claude API');
        }

        // Log token usage
        if (isset($data['usage'])) {
            $usage = $data['usage'];
            \Helper::log('github_ai', 'Claude response: ' . ($usage['input_tokens'] ?? 0) . ' input + ' . ($usage['output_tokens'] ?? 0) . ' output tokens');
        }

        if (isset($data['content'][0]['text'])) {
            $contentString = $data['content'][0]['text'];

            if (empty($contentString)) {
                $stopReason = $data['stop_reason'] ?? 'unknown';
                \Helper::log('github_ai', 'ERROR: Empty content from Claude, stop_reason: ' . $stopReason);
                throw new \Exception('Failed to generate content: Claude returned empty content (stop_reason: ' . $stopReason . ')');
            }

            // Claude sometimes wraps JSON in markdown code blocks or adds text around it
            $content = json_decode($contentString, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                // Try to extract JSON from markdown code blocks
                if (preg_match('/```(?:json)?\s*([\s\S]*?)\s*```/', $contentString, $jsonMatch)) {
                    $content = json_decode(trim($jsonMatch[1]), true);
                }
                // Try to extract JSON object directly from surrounding text
                if (json_last_error() !== JSON_ERROR_NONE && preg_match('/\{[\s\S]*"title"[\s\S]*"body"[\s\S]*\}/', $contentString, $jsonMatch)) {
                    $content = json_decode(trim($jsonMatch[0]), true);
                }
                if (json_last_error() !== JSON_ERROR_NONE) {
                    \Helper::log('github_ai', 'ERROR: Invalid JSON content from Claude - ' . json_last_error_msg() . ' - Raw: ' . substr($contentString, 0, 500));
                    throw new \Exception('Failed to generate content: Claude response was not valid JSON');
                }
                \Helper::log('github_ai', 'Extracted JSON from Claude response (was wrapped in text/markdown)');
            }

            if ($content && isset($content['title'], $content['body'])) {
                $labelCount = isset($content['suggested_labels']) ? count($content['suggested_labels']) : 0;
                \Helper::log('github_ai', 'SUCCESS: Generated title (' . strlen($content['title']) . ' chars), body (' . strlen($content['body']) . ' chars), ' . $labelCount . ' labels');

                // Filter suggested labels based on allowed labels setting
                $content = $this->filterSuggestedLabels($content);

                // Post-process to inject conversation JSON
                return $this->injectConversationContext($content, $conversation);
            }

            \Helper::log('github_ai', 'ERROR: Claude response missing required title/body fields');
            throw new \Exception('Failed to generate content: Claude response missing required title or body fields');
        }

        \Helper::log('github_ai', 'ERROR: Claude response missing content field');
        throw new \Exception('Failed to generate content: Claude response missing content field');
    }

    /**
     * Generate content manually without AI
     */
    private function generateManualContent(Conversation $conversation)
    {
        $subject = $conversation->subject;
        
        // Get all messages including support team analysis for better context
        $threads = $conversation->threads()
            ->whereIn('type', [\App\Thread::TYPE_CUSTOMER, \App\Thread::TYPE_MESSAGE, \App\Thread::TYPE_NOTE])
            ->orderBy('created_at')
            ->limit(8) // Increased to capture support analysis
            ->get();
        
        // Extract conversation summary and diagnostic info
        $conversationSummary = $this->extractConversationSummary($threads);
        $conversationText = $this->extractConversationText($conversation);
        $diagnosticInfo = $this->extractDiagnosticInfo($conversationText);
        $technicalDetails = $this->extractTechnicalDetails($threads);
        $customerMessage = '';
        
        $firstCustomerThread = $threads->where('type', \App\Thread::TYPE_CUSTOMER)->first();
        if ($firstCustomerThread) {
            $customerMessage = \Helper::utf8Encode(strip_tags($firstCustomerThread->body));
            $customerMessage = strlen($customerMessage) > 800 ? 
                substr($customerMessage, 0, 800) . '...' : 
                $customerMessage;
        }

        // Get customer info safely
        $customerName = 'Unknown Customer';
        $customerEmail = 'No email';
        if ($conversation->customer) {
            $customerName = $conversation->customer->getFullName() ?: 'Unknown Customer';
            $customerEmail = $conversation->customer->getMainEmail() ?: 'No email';
        }

        // Generate title
        $title = $subject;
        if (empty($title)) {
            $title = 'Support Request from ' . $customerName;
        }

        // Check for custom manual template
        $customTemplate = \Option::get('github.manual_template');
        
        if (!empty($customTemplate)) {
            // Prepare conversation summary with fallback text
            $summaryText = $conversationSummary;
            if (!$summaryText) {
                $aiService = \Option::get('github.ai_service');
                $aiApiKey = \Option::get('github.ai_api_key');
                
                if (empty($aiService) || empty($aiApiKey)) {
                    $summaryText = "_No AI service configured. To get intelligent summaries, configure OpenAI or Claude API in GitHub module settings._";
                } else {
                    $summaryText = "_AI summary generation failed. Using manual template._";
                }
            }
            
            // Use custom template with variable replacement
            $body = str_replace([
                '{customer_name}',
                '{customer_email}',
                '{subject}',
                '{conversation_url}',
                '{conversation_json}',
                '{status}',
                '{created_at}',
                '{customer_message}',
                '{conversation_summary}',
                '{technical_details}',
                '{thread_count}'
            ], [
                $customerName,
                $customerEmail,
                $subject,
                url("/conversation/" . $conversation->id),
                $conversationText, // Full conversation JSON
                ucfirst($conversation->getStatusName()),
                $conversation->created_at->format('Y-m-d H:i:s'),
                $customerMessage ?: 'No customer message available',
                $summaryText,
                $technicalDetails ?: 'No technical details found',
                $threads->count()
            ], $customTemplate);
        } else {
            // Default template generation
            $body = "## Summary\n\n";
            if ($conversationSummary) {
                $body .= $conversationSummary . "\n\n";
            } else {
                // Check if AI service is configured
                $aiService = \Option::get('github.ai_service');
                $aiApiKey = \Option::get('github.ai_api_key');
                
                if (empty($aiService) || empty($aiApiKey)) {
                    $body .= "_No AI service configured. To get intelligent summaries, configure OpenAI or Claude API in GitHub module settings._\n\n";
                } else {
                    $body .= "_AI summary generation failed. Using manual template._\n\n";
                }
            }
            
            $body .= "## Customer Information\n\n";
            $body .= "- **Name:** " . $customerName . "\n";
            $body .= "- **Email:** " . $customerEmail . "\n";
            $body .= "- **Subject:** " . $subject . "\n\n";
            
            // Add AI-extracted diagnostic information if available
            if ($diagnosticInfo) {
                if (isset($diagnosticInfo['reproduction_confirmed']) && $diagnosticInfo['reproduction_confirmed']) {
                    $body .= "## Reproduction Status\n\n";
                    $body .= "✅ **Confirmed** - Support team successfully reproduced this issue\n\n";
                }
                
                if (!empty($diagnosticInfo['root_cause'])) {
                    $body .= "## Root Cause Analysis\n\n";
                    $body .= $diagnosticInfo['root_cause'] . "\n\n";
                }
                
                if (!empty($diagnosticInfo['symptoms'])) {
                    $body .= "## Symptoms\n\n";
                    foreach ($diagnosticInfo['symptoms'] as $symptom) {
                        $body .= "- " . $symptom . "\n";
                    }
                    $body .= "\n";
                }
                
                if (!empty($diagnosticInfo['conflicting_plugins'])) {
                    $body .= "## Plugin Conflicts\n\n";
                    foreach ($diagnosticInfo['conflicting_plugins'] as $plugin) {
                        $body .= "- " . $plugin . "\n";
                    }
                    $body .= "\n";
                }
                
                if (!empty($diagnosticInfo['support_analysis'])) {
                    $body .= "## Support Team Analysis\n\n";
                    foreach ($diagnosticInfo['support_analysis'] as $analysis) {
                        $body .= "- " . $analysis . "\n";
                    }
                    $body .= "\n";
                }
                
                if (!empty($diagnosticInfo['customer_environment'])) {
                    $body .= "## Customer Environment\n\n";
                    foreach ($diagnosticInfo['customer_environment'] as $key => $value) {
                        $body .= "- **" . ucfirst(str_replace('_', ' ', $key)) . ":** " . $value . "\n";
                    }
                    $body .= "\n";
                }
            }
            
            if ($technicalDetails) {
                $body .= "## Technical Details\n\n";
                $body .= $technicalDetails . "\n\n";
            }
            
            if ($customerMessage) {
                $body .= "## Original Message\n\n";
                $body .= "```\n" . $customerMessage . "\n```\n\n";
            }
            
            // Add conversation thread summary
            if ($threads->count() > 1) {
                $body .= "## Conversation History\n\n";
                foreach ($threads->take(3) as $thread) {
                    $sender = $thread->type === \App\Thread::TYPE_CUSTOMER ? '👤 Customer' : '🏢 Support';
                    $preview = \Helper::utf8Encode(strip_tags($thread->body));
                    $preview = strlen($preview) > 200 ? substr($preview, 0, 200) . '...' : $preview;
                    $body .= "**{$sender}** (" . $thread->created_at->format('M d, H:i') . "):\n";
                    $body .= "> " . str_replace("\n", "\n> ", $preview) . "\n\n";
                }
            }

            // Add conversation context for AI
            $body .= "## Conversation Context (Last 7 Days)\n\n";
            $body .= "The following JSON contains the full conversation history for AI analysis:\n\n";
            $body .= $conversationText . "\n\n";
            
            $body .= "## Metadata\n\n";
            $body .= "- **Status:** " . ucfirst($conversation->getStatusName()) . "\n";
            $body .= "- **Created:** " . $conversation->created_at->format('Y-m-d H:i:s') . "\n";
            $body .= "- **Messages:** " . $threads->count() . "\n";
        }

        return [
            'title' => $title,
            'body' => $body
        ];
    }

    /**
     * Post-process AI response to inject conversation context
     */
    private function injectConversationContext($content, Conversation $conversation)
    {
        // Extract conversation JSON
        $conversationText = $this->extractConversationText($conversation);
        
        $body = $content['body'];
        
        // Look for FreeScout link section and replace it with conversation JSON
        $patterns = [
            '/##\s*FreeScout\s*Link\s*\n+.*?(?=\n##|\z)/si',
            '/##\s*Related\s*Conversation\s*\n+.*?(?=\n##|\z)/si',
            '/\*\*FreeScout\s*Link\*\*:\s*.*?\n/i',
            '/\[View in FreeScout\]\(.*?\)\n?/i'
        ];
        
        $foundLink = false;
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $body)) {
                $foundLink = true;
                $body = preg_replace($pattern, '', $body);
                break;
            }
        }
        
        // Add conversation context section
        $conversationSection = "\n## Conversation Context (Last 7 Days)\n\n";
        $conversationSection .= "The following JSON contains the full conversation history for AI analysis:\n\n";
        $conversationSection .= $conversationText . "\n";
        
        // If we found and removed a FreeScout link, insert the conversation JSON in its place
        if ($foundLink) {
            // Find a good place to insert (before the last section or at the end)
            if (preg_match('/\n(##[^\n]+)$/', $body, $matches, PREG_OFFSET_CAPTURE)) {
                // Insert before the last section
                $insertPos = $matches[0][1];
                $body = substr($body, 0, $insertPos) . $conversationSection . substr($body, $insertPos);
            } else {
                // Just append at the end
                $body .= $conversationSection;
            }
        } else {
            // No FreeScout link found, append at the end
            $body .= $conversationSection;
        }
        
        $content['body'] = $body;
        return $content;
    }

    /**
     * Extract conversation text for AI processing
     */
    private function extractConversationText(Conversation $conversation)
    {
        // Get threads from the past 7 days only
        $sevenDaysAgo = \Carbon\Carbon::now()->subDays(7);
        
        $threads = $conversation->threads()
            ->whereIn('type', [\App\Thread::TYPE_CUSTOMER, \App\Thread::TYPE_MESSAGE, \App\Thread::TYPE_NOTE])
            ->where('created_at', '>=', $sevenDaysAgo)
            ->orderBy('created_at')
            ->limit(20) // Increased limit since we're filtering by date
            ->get();

        // Build structured conversation data with UTF-8 sanitization
        $conversationData = [
            'subject' => $this->sanitizeForGPT5Mini($conversation->subject),
            'created_at' => $conversation->created_at->toIso8601String(),
            'messages' => []
        ];
        
        foreach ($threads as $index => $thread) {
            // Determine sender type more accurately
            $sender = 'Support';
            $senderName = 'Support Team';
            
            if ($thread->type === \App\Thread::TYPE_CUSTOMER) {
                $sender = 'Customer';
                $rawName = $thread->created_by ? $thread->created_by->getFullName() : 'Customer';
                $senderName = $this->sanitizeForGPT5Mini($rawName);
            } elseif ($thread->type === \App\Thread::TYPE_NOTE) {
                $sender = 'Support Team (Internal Note)';
                $rawName = $thread->created_by ? $thread->created_by->getFullName() : 'Support Team';
                $senderName = $this->sanitizeForGPT5Mini($rawName);
            } elseif ($thread->created_by && $thread->created_by->isCustomer()) {
                $sender = 'Customer';
                $rawName = $thread->created_by->getFullName();
                $senderName = $this->sanitizeForGPT5Mini($rawName);
            } elseif ($thread->created_by) {
                $rawName = $thread->created_by->getFullName();
                $senderName = $this->sanitizeForGPT5Mini($rawName);
            }
            
            $rawBody = $this->extractStructuredContent($thread->body);
            $filteredBody = $this->filterExternalLinks($rawBody);
            $body = $this->sanitizeForGPT5Mini($filteredBody); // Sanitize message content
            
            $conversationData['messages'][] = [
                'timestamp' => $thread->created_at->toIso8601String(),
                'sender_type' => $this->sanitizeForGPT5Mini($sender),
                'sender_name' => $senderName,
                'message' => $body
            ];
        }

        // Format as JSON in markdown block for better AI parsing
        $jsonData = json_encode($conversationData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        
        // Check for JSON encoding errors
        if (json_last_error() !== JSON_ERROR_NONE) {
            \Helper::log('github_ai', 'JSON encoding failed: ' . json_last_error_msg());
            
            // Fallback: try with basic encoding
            $jsonData = json_encode($conversationData);
            if (!$jsonData) {
                // Ultimate fallback: basic text representation
                $jsonData = "{\n  \"subject\": \"" . addslashes($conversationData['subject']) . "\",\n  \"messages\": " . count($conversationData['messages']) . " messages\n}";
            }
        }
        
        return "```json\n" . $jsonData . "\n```";
    }

    /**
     * Remove email signatures and repetitive content to save tokens
     */
    private function removeEmailSignatures($html)
    {
        // Remove common email signature patterns
        $patterns = [
            // Outlook/email signature divs
            '/<div[^>]*id=["\']?Signature["\']?[^>]*>.*?<\/div>/si',
            '/<div[^>]*class=["\'][^"\']*signature[^"\']*["\'][^>]*>.*?<\/div>/si',
            
            // Social media icon sections (multiple consecutive image links)
            '/<a[^>]*href=["\'][^"\']*(?:facebook|twitter|instagram|linkedin|youtube|tiktok)[^"\']*["\'][^>]*>.*?<\/a>\s*(?:<a[^>]*href=["\'][^"\']*(?:facebook|twitter|instagram|linkedin|youtube|tiktok)[^"\']*["\'][^>]*>.*?<\/a>\s*){2,}/si',
            
            // Large embedded images (logos, signatures)
            '/<img[^>]*(?:width=["\']?[2-9]\d{2,}|height=["\']?[2-9]\d{2,})[^>]*>/si',
            '/<img[^>]*src=["\'][^"\']*(?:googleusercontent|lh[0-9]\.google)[^"\']*["\'][^>]*>/si',
            
            // Contact information blocks
            '/<p[^>]*>.*?(?:office|phone|hours|my hours):\s*[^<]*<\/p>/si',
            
            // Review request sections
            '/Happy with our programs.*?<\/div>/si',
            '/Please leave us a.*?review.*?<\/div>/si',
            
            // Multiple consecutive <br> tags
            '/(?:<br[^>]*>\s*){3,}/si',
            
            // Empty divs and paragraphs
            '/<(?:div|p)[^>]*>\s*(?:<br[^>]*>\s*)*<\/(?:div|p)>/si',
        ];
        
        foreach ($patterns as $pattern) {
            $html = preg_replace($pattern, '', $html);
        }
        
        // Clean up extra whitespace
        $html = preg_replace('/\s+/', ' ', $html);
        $html = trim($html);
        
        return $html;
    }

    /**
     * Filter out external and private links that AI can't access
     */
    private function filterExternalLinks($text)
    {
        // Remove common external/private link patterns
        $patterns = [
            '/https?:\/\/www\.loom\.com\/[^\s]+/i',           // Loom videos
            '/https?:\/\/[^\/\s]*\.loom\.com\/[^\s]+/i',     // Any loom subdomain
            '/https?:\/\/drive\.google\.com\/[^\s]+/i',      // Google Drive
            '/https?:\/\/dropbox\.com\/[^\s]+/i',            // Dropbox
            '/https?:\/\/[^\/\s]*\.sharepoint\.com\/[^\s]+/i', // SharePoint
            '/https?:\/\/[^\/\s]*support\.[^\/\s]+\/[^\s]+/i', // Support portals
            '/https?:\/\/support\.[^\/\s]+\/[^\s]+/i',       // Support subdomains
        ];
        
        foreach ($patterns as $pattern) {
            $text = preg_replace($pattern, '[External link removed]', $text);
        }
        
        return $text;
    }

    /**
     * Extract structured content from HTML, preserving form field structure
     */
    private function extractStructuredContent($html)
    {
        // Clean UTF-8 encoding before processing
        $html = \Helper::utf8Encode($html);
        
        // Remove email signatures and repetitive content to save tokens
        $html = $this->removeEmailSignatures($html);
        
        // Check if this looks like a structured HTML table form
        if (strpos($html, '<table') !== false && strpos($html, '<strong>') !== false) {
            return $this->parseHTMLTable($html);
        }
        
        // Fall back to regular strip_tags for simple content
        return \Helper::utf8Encode(strip_tags($html));
    }

    /**
     * Parse HTML table structure to extract form fields
     */
    private function parseHTMLTable($html)
    {
        try {
            $structured = [];
            
            // Create DOMDocument to parse HTML properly
            $dom = new \DOMDocument();
            
            // Suppress HTML parsing warnings for malformed HTML
            libxml_use_internal_errors(true);
            $dom->loadHTML('<?xml encoding="utf-8" ?>' . $html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
            libxml_clear_errors();
            
            // Find all table rows
            $rows = $dom->getElementsByTagName('tr');
            $currentField = null;
            
            foreach ($rows as $row) {
                $cells = $row->getElementsByTagName('td');
                
                if ($cells->length >= 2) {
                    $firstCell = \Helper::utf8Encode(trim($cells->item(0)->textContent));
                    $secondCell = \Helper::utf8Encode(trim($cells->item(1)->textContent));
                    
                    // Check if first cell contains a field label (has <strong> tag)
                    $strongTags = $cells->item(0)->getElementsByTagName('strong');
                    if ($strongTags->length > 0) {
                        $currentField = \Helper::utf8Encode(trim($strongTags->item(0)->textContent));
                    } else if (!empty($secondCell) && !empty($currentField) && $secondCell !== '&nbsp;') {
                        // This is a value row for the current field
                        $structured[$currentField] = $secondCell;
                        $currentField = null;
                    }
                }
            }
            
            // Format the structured data
            $formatted = [];
            foreach ($structured as $field => $value) {
                if (!empty($value) && $value !== '&nbsp;') {
                    $formatted[] = "{$field}: {$value}";
                }
            }
            
            $result = implode("\n", $formatted);
            
            // If we got structured data, return it, otherwise fall back to strip_tags
            return !empty($result) ? $result : \Helper::utf8Encode(strip_tags($html));
            
        } catch (\Exception $e) {
            // If HTML parsing fails, fall back to strip_tags
            return \Helper::utf8Encode(strip_tags($html));
        }
    }

    /**
     * Build AI prompt for content generation
     */
    private function buildPrompt($conversationText, Conversation $conversation, $availableLabels = [])
    {
        $customerName = 'Unknown Customer';
        $customerEmail = 'No email';
        
        if ($conversation->customer) {
            $customerName = $this->sanitizeForGPT5Mini($conversation->customer->getFullName() ?: 'Unknown Customer');
            $customerEmail = $this->sanitizeForGPT5Mini($conversation->customer->getMainEmail() ?: 'No email');
        } else {
            // Try to load customer manually if the relationship didn't work
            if (!empty($conversation->customer_id)) {
                $customer = \App\Customer::find($conversation->customer_id);
                if ($customer) {
                    $customerName = $this->sanitizeForGPT5Mini($customer->getFullName() ?: 'Unknown Customer');
                    $customerEmail = $this->sanitizeForGPT5Mini($customer->getMainEmail() ?: 'No email');
                }
            }
        }
        
        $conversationUrl = url("/conversation/" . $conversation->id);
        $status = ucfirst($conversation->getStatusName());
        
        // Build available labels text with UTF-8 encoding
        if (empty($availableLabels)) {
            $availableLabelsText = 'bug, enhancement, documentation, question';
        } else {
            // Ensure all labels are UTF-8 encoded
            $encodedLabels = array_map(function($label) {
                return $this->sanitizeForGPT5Mini($label);
            }, $availableLabels);
            $availableLabelsText = implode(', ', $encodedLabels);
        }
        
        // Check for custom AI prompt template
        $customPrompt = \Option::get('github.ai_prompt_template');
        
        if (!empty($customPrompt)) {
            // Use custom template with variable replacement
            $prompt = str_replace([
                '{customer_name}',
                '{customer_email}',
                '{conversation_url}',
                '{conversation_json}',
                '{status}',
                '{conversation_text}',
                '{available_labels}'
            ], [
                $customerName,
                $customerEmail,
                $conversationUrl,
                $conversationText, // Same as conversation_json
                $status,
                $conversationText,
                $availableLabelsText
            ], $customPrompt);
            
            return $prompt;
        }

        // Optimized prompt template for token efficiency
        $prompt = "Create a GitHub issue from this support conversation.

Customer: $customerName ($customerEmail)
Status: $status

$conversationText

Create JSON with:
1. **title**: Clear issue title (max 80 chars)
2. **body**: Markdown formatted with sections:
   - Problem Summary
   - Customer: $customerName ($customerEmail)
   - Root Cause Analysis (focus on support team diagnostic findings)
   - Steps to Reproduce
   - Plugin Conflicts (if any)
   - Support Team Findings (from internal notes)
   - Customer Environment
3. **suggested_labels**: 2-4 labels from: $availableLabelsText

Focus on support team internal notes for diagnostic info. Be technical and actionable.

JSON format:
{\"title\":\"...\",\"body\":\"...\",\"suggested_labels\":[\"...\"]}";

        return $prompt;
    }

    /**
     * Extract a summary from conversation threads
     */
    private function extractConversationSummary($threads)
    {
        // Without AI, we can't generate a true summary
        // This would be replaced by AI analysis
        return null;
    }

    /**
     * Extract diagnostic information without relying on an external AI call.
     */
    private function extractDiagnosticInfo($conversationText)
    {
        $info = [
            'reproduction_confirmed' => false,
            'root_cause' => null,
            'issue_type' => null,
            'symptoms' => [],
            'conflicting_plugins' => [],
            'technical_details' => [],
            'reproduction_steps' => [],
            'support_analysis' => [],
            'customer_environment' => [],
        ];

        $structured = $this->decodeConversationJson($conversationText);
        $messages = $structured['messages'] ?? [];

        foreach ($messages as $message) {
            $body = (string) ($message['message'] ?? '');
            if ($body === '') {
                continue;
            }

            $lowerBody = strtolower($body);

            if (!$info['reproduction_confirmed'] && preg_match('/reproduc(ed|e|ing)|able to replicate|able to reproduce/i', $body)) {
                $info['reproduction_confirmed'] = true;
            }

            if ($info['root_cause'] === null && (strpos($lowerBody, 'root cause') !== false || strpos($lowerBody, 'because') !== false)) {
                $info['root_cause'] = Str::limit($body, 280);
            }

            if ($info['issue_type'] === null) {
                $info['issue_type'] = $this->detectIssueType($lowerBody);
            }

            if (preg_match_all('/error:? (.+?)(?:\.|\n|$)/i', $body, $errorMatches)) {
                foreach ($errorMatches[1] as $error) {
                    $info['symptoms'][] = trim($error);
                }
            }

            if (preg_match_all('/([A-Z][A-Za-z0-9\s]+)\s+plugin/i', $body, $pluginMatches)) {
                foreach ($pluginMatches[1] as $plugin) {
                    $info['conflicting_plugins'][] = trim($plugin);
                }
            }

            if (preg_match_all('/step\s*\d*[:\-]\s*(.+)/i', $body, $stepMatches)) {
                foreach ($stepMatches[1] as $step) {
                    $info['reproduction_steps'][] = trim($step);
                }
            }

            $sender = strtolower($message['sender_type'] ?? '');
            if (strpos($sender, 'support') !== false) {
                $info['support_analysis'][] = Str::limit($body, 200);
            }
        }

        $this->populateEnvironmentDetails($conversationText, $info);
        $this->populateTechnicalDetails($conversationText, $info);

        $info['symptoms'] = array_values(array_unique(array_filter($info['symptoms'])));
        $info['conflicting_plugins'] = array_values(array_unique(array_filter($info['conflicting_plugins'])));
        $info['reproduction_steps'] = array_values(array_unique(array_filter($info['reproduction_steps'])));
        $info['support_analysis'] = array_values(array_unique(array_filter($info['support_analysis'])));

        return $info;
    }

    private function decodeConversationJson(string $conversationText): ?array
    {
        if (preg_match('/```json\s*(.*?)\s*```/is', $conversationText, $matches)) {
            $json = trim($matches[1]);
            $decoded = json_decode($json, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                return $decoded;
            }
        }

        return null;
    }

    private function detectIssueType(string $lowerBody): ?string
    {
        $map = [
            'CSS' => ['css', 'stylesheet', 'style'],
            'JavaScript' => ['javascript', 'js error', 'console', 'script'],
            'Performance' => ['slow', 'performance', 'timeout'],
            'API' => ['api', 'webhook', 'endpoint'],
            'Database' => ['database', 'sql', 'query'],
        ];

        foreach ($map as $type => $keywords) {
            foreach ($keywords as $keyword) {
                if (strpos($lowerBody, $keyword) !== false) {
                    return $type;
                }
            }
        }

        return null;
    }

    private function populateEnvironmentDetails(string $conversationText, array &$info): void
    {
        if (preg_match('/WordPress\s+([0-9\.]+)/i', $conversationText, $match)) {
            $info['customer_environment']['wordpress_version'] = $match[1];
        }

        if (preg_match('/WooCommerce\s+([0-9\.]+)/i', $conversationText, $match)) {
            $info['customer_environment']['woocommerce_version'] = $match[1];
        }

        if (preg_match('/PHP\s+([0-9\.]+)/i', $conversationText, $match)) {
            $info['customer_environment']['php_version'] = $match[1];
        }
    }

    private function populateTechnicalDetails(string $conversationText, array &$info): void
    {
        if (preg_match_all('/(Fatal error|Warning|Notice):\s*(.+?)(?:\.|\n)/i', $conversationText, $matches)) {
            foreach ($matches[0] as $detail) {
                $info['technical_details'][] = trim($detail);
            }
        }

        $info['technical_details'] = array_values(array_unique(array_filter($info['technical_details'])));
    }

    /**
     * Extract technical details from conversation (fallback method)
     */
    private function extractTechnicalDetails($threads)
    {
        // Simple fallback extraction without regex complexity
        $details = [];
        
        foreach ($threads as $thread) {
            $body = strip_tags($thread->body);
            
            // Look for URLs (excluding FreeScout)
            if (preg_match_all('/(https?:\/\/[^\s]+)/i', $body, $matches)) {
                foreach ($matches[1] as $url) {
                    if (!strpos($url, 'freescout') && !strpos($url, 'support.')) {
                        $details[] = "URL mentioned: " . $url;
                    }
                }
            }
        }
        
        return $details ? implode("\n", array_unique($details)) : null;
    }

    /**
     * Filter suggested labels based on allowed labels setting
     */
    private function filterSuggestedLabels($content)
    {
        if (!isset($content['suggested_labels']) || !is_array($content['suggested_labels'])) {
            return $content;
        }

        // Get allowed labels setting
        $allowedLabelsJson = \Option::get('github.allowed_labels', '[]');
        
        // Handle case where the setting might already be an array or a JSON string
        if (is_array($allowedLabelsJson)) {
            $allowedLabels = $allowedLabelsJson;
        } else {
            $allowedLabels = json_decode($allowedLabelsJson, true);
        }
        
        // Ensure we have a valid array
        if (!is_array($allowedLabels)) {
            $allowedLabels = [];
        }
        
        // If no allowed labels are configured, allow all (backward compatibility)
        if (empty($allowedLabels)) {
            return $content;
        }

        // Filter suggested labels to only include allowed ones
        $originalCount = count($content['suggested_labels']);
        $content['suggested_labels'] = array_values(array_intersect($content['suggested_labels'], $allowedLabels));
        $filteredCount = count($content['suggested_labels']);
        
        if ($originalCount !== $filteredCount) {
            \Helper::log('github_ai', 'Filtered suggested labels: ' . $originalCount . ' -> ' . $filteredCount . ' (removed ' . ($originalCount - $filteredCount) . ' disallowed labels)');
        }

        return $content;
    }
}