<?php
namespace TwitchGpt\Services;

use OpenAI;

class Gpt {
    private const CONTEXT_CACHE_TTL = 3600;
    private const PROMPT_CACHE_LIMIT = 10;
    private const MEMORY_LIMIT = 5;
    private array $timings = [];
    
    /**
     * Get an instance of the OpenAI Client
     * @return OpenAI\Client $client The Client Instance
     */
    public function getClient() : OpenAI\Client
    {
        $openAiApiKey = env('OPENAI_API_KEY');
        $client = OpenAI::client($openAiApiKey);
        return $client;
    }

    /**
     * Clean the users prompt of bad stuff.
     * 
     * @param   string $prompt Prompt Text from User
     * @return  string $prompt Santized Prompt Text
     */
    public function cleanPrompt($prompt)
    {
        $prompt = trim($prompt);
        // Remove non-printable ASCII characters (control characters)
        $prompt = preg_replace('/[\x00-\x1F\x7F]/', '', $prompt);
        $prompt = strip_tags($prompt);
        // Allow letters, numbers, spaces, apostrophes, periods, commas, question marks, exclamation points, and hyphens
        $prompt = preg_replace("/[^a-zA-Z0-9'.,?!\- ]/", '', $prompt);
        return $prompt;
    }

    public function shouldUseWebSearch($prompt)
    {
        return preg_match('/!search|search/i', $prompt) === 1;
    }

    public function stripWebSearchTrigger($prompt)
    {
        $prompt = preg_replace('/!search/i', ' ', $prompt);
        $prompt = preg_replace('/search/i', ' ', $prompt);

        return trim(preg_replace('/\s+/', ' ', $prompt));
    }


    /**
     * Merge Prompt with Context
     * 
     * @param   string $prompt The (hope you sanitized it beforehand) prompt
     * @return  string $promptWithContext The prompt with the context added beforehand.
     */
    public function mergePromptWithContext($prompt)
    {
        // Don't send shit to the API
        $cleanPrompt = $this->cleanPrompt($prompt);
        // fetch the contextual info
        $context = $this->getContext();
        // merge user request (cleaned) with context.
        $promptWithContext = $context . $cleanPrompt;
        // Return our prompt with context for use with the OpenAI API
        return $promptWithContext;
    }

    /**
     * Fetch the context on it's own
     * 
     * @return  string|null $context    
     */
    public function getContext()
    {
        $startedAt = microtime(true);
        $contextUrl = env('OPENAI_PROMPT_CONTEXT_URL');
        if (!$contextUrl) {
            $this->timings['context'] = [
                'source' => 'disabled',
                'duration_ms' => 0,
            ];

            return null;
        }

        $cacheFile = $this->getContextCacheFile($contextUrl);

        if (is_file($cacheFile) && (time() - filemtime($cacheFile)) < self::CONTEXT_CACHE_TTL) {
            $cachedContext = file_get_contents($cacheFile);

            if ($cachedContext !== false) {
                $this->timings['context'] = [
                    'source' => 'cache',
                    'duration_ms' => $this->getDurationMs($startedAt),
                ];

                return $cachedContext;
            }
        }

        $httpClient = new \GuzzleHttp\Client();

        try {
            $response = $httpClient->request('GET', $contextUrl);
            $context = $response->getBody()->getContents();
            $cacheDirectory = dirname($cacheFile);

            if (!is_dir($cacheDirectory)) {
                mkdir($cacheDirectory, 0775, true);
            }

            file_put_contents($cacheFile, $context, LOCK_EX);

            $this->timings['context'] = [
                'source' => 'remote',
                'duration_ms' => $this->getDurationMs($startedAt),
            ];

            return $context;
        } catch (\Throwable $exception) {
            if (is_file($cacheFile)) {
                $cachedContext = file_get_contents($cacheFile);

                if ($cachedContext !== false) {
                    $this->timings['context'] = [
                        'source' => 'stale-cache',
                        'duration_ms' => $this->getDurationMs($startedAt),
                    ];

                    return $cachedContext;
                }
            }
        }

        $this->timings['context'] = [
            'source' => 'failed',
            'duration_ms' => $this->getDurationMs($startedAt),
        ];

        return null;
    }

    /**
     * Get the cache file path for the remote context.
     *
     * @param string $contextUrl
     * @return string
     */
    private function getContextCacheFile($contextUrl)
    {
        return dirname(__DIR__, 2) . '/cache/context-' . md5($contextUrl) . '.txt';
    }

    /**
     * Get a response from OpenAI
     * 
     * @param   string $user The name of the user
     * @param   string $prompt The (hopefully sanitized beforehand) prompt
     * 
     * @return  string $result The reply from OpenAI
     */
    public function getResponse($user, $prompt, $useWebSearch = false) 
    {
        $startedAt = microtime(true);
        $history = $this->loadConversationHistory();
        $cachedResponse = $this->findCachedResponse($history, $prompt, $useWebSearch);

        if ($cachedResponse !== null) {
            $this->recordConversation($history, $user, $prompt, $cachedResponse, $useWebSearch);
            $this->timings['openai'] = [
                'duration_ms' => 0,
                'model' => 'cache',
                'web_search' => $useWebSearch,
                'cache_hit' => true,
            ];
            $this->timings['memory'] = [
                'entries_sent' => 0,
            ];
            $this->timings['total'] = [
                'duration_ms' => $this->getDurationMs($startedAt),
            ];

            return $cachedResponse;
        }

        $client = $this->getClient();
        $context = $this->getContext();
        $memory = $this->buildConversationMemory($history);

        $parameters = [
            'model' => 'gpt-5-mini',
            'reasoning' => [
                'effort' => $useWebSearch ? 'low' : 'minimal',
            ],
            'input' => array_merge($memory, [
                [
                    'role' => 'user',
                    'content' => "{$user}: {$prompt}",
                ],
            ]),
        ];

        if ($context !== null) {
            $parameters['instructions'] = $context;
        }

        if ($useWebSearch) {
            $parameters['tools'] = [
                ['type' => 'web_search_preview'],
            ];
        }

        $openAiStartedAt = microtime(true);
        $result = $client->responses()->create($parameters);
        $this->timings['openai'] = [
            'duration_ms' => $this->getDurationMs($openAiStartedAt),
            'model' => 'gpt-5-mini',
            'web_search' => $useWebSearch,
            'cache_hit' => false,
        ];
        $this->timings['memory'] = [
            'entries_sent' => count($memory),
        ];
        
        $reply = trim((string) $result->outputText);
        $reply = $this->convertMarkdownLinksToRawLinks($reply);

        // Twitch character limit is 399
        if (strlen($reply) > 399) {
            $reply = substr($reply, 0, 399);
        }

        $this->recordConversation($history, $user, $prompt, $reply, $useWebSearch);

        $this->timings['total'] = [
            'duration_ms' => $this->getDurationMs($startedAt),
        ];
    
        return $reply;
    }

    public function getTimings()
    {
        return $this->timings;
    }

    private function getDurationMs($startedAt)
    {
        return (int) round((microtime(true) - $startedAt) * 1000);
    }

    private function convertMarkdownLinksToRawLinks($text)
    {
        return preg_replace('/\[([^\]]+)\]\((https?:\/\/[^\s)]+)\)/', '$2', $text);
    }

    private function loadConversationHistory()
    {
        $historyFile = $this->getConversationHistoryFile();

        if (!is_file($historyFile)) {
            return [];
        }

        $contents = file_get_contents($historyFile);

        if ($contents === false || $contents === '') {
            return [];
        }

        $history = json_decode($contents, true);

        return is_array($history) ? $history : [];
    }

    private function saveConversationHistory($history)
    {
        $historyFile = $this->getConversationHistoryFile();
        file_put_contents($historyFile, json_encode(array_values($history), JSON_PRETTY_PRINT), LOCK_EX);
    }

    private function getConversationHistoryFile()
    {
        return dirname(__DIR__, 2) . '/cache/conversation-history.json';
    }

    private function findCachedResponse($history, $prompt, $useWebSearch)
    {
        $recentHistory = array_slice(array_reverse($history), 0, self::PROMPT_CACHE_LIMIT);

        foreach ($recentHistory as $entry) {
            $cachedPrompt = $entry['cache_prompt'] ?? ($entry['prompt'] ?? null);

            if ($cachedPrompt === $prompt && ($entry['use_web_search'] ?? false) === $useWebSearch) {
                return $entry['response'] ?? null;
            }
        }

        return null;
    }

    private function buildConversationMemory($history)
    {
        $recentHistory = array_slice($history, -self::MEMORY_LIMIT);
        $memory = [];

        foreach ($recentHistory as $entry) {
            if (!isset($entry['user'], $entry['prompt'], $entry['response'])) {
                continue;
            }

            $memory[] = [
                'role' => 'user',
                'content' => $entry['user'] . ': ' . $entry['prompt'],
            ];
            $memory[] = [
                'role' => 'assistant',
                'content' => $entry['response'],
            ];
        }

        return $memory;
    }

    private function recordConversation($history, $user, $prompt, $response, $useWebSearch)
    {
        $history[] = [
            'user' => $user,
            'prompt' => $useWebSearch ? '!search ' . $prompt : $prompt,
            'cache_prompt' => $prompt,
            'response' => $response,
            'use_web_search' => $useWebSearch,
            'created_at' => time(),
        ];

        $maxEntries = max(self::PROMPT_CACHE_LIMIT, self::MEMORY_LIMIT);
        $this->saveConversationHistory(array_slice($history, -$maxEntries));
    }
}
