<?php
namespace TwitchGpt\Services;

use OpenAI;

class Gpt {
    private const CONTEXT_CACHE_TTL = 3600;
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
        $client = $this->getClient();
        $context = $this->getContext();

        $parameters = [
            'model' => 'gpt-5-mini',
            'reasoning' => [
                'effort' => $useWebSearch ? 'low' : 'minimal',
            ],
            'input' => [
                [
                    'role' => 'user',
                    'content' => "{$user}: {$prompt}",
                ],
            ],
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
        ];
        
        $reply = trim((string) $result->outputText);

        // Twitch character limit is 399
        if (strlen($reply) > 399) {
            $reply = substr($reply, 0, 399);
        }

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
}
