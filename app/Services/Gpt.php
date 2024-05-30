<?php
namespace TwitchGpt\Services;

use OpenAI;

class Gpt {
    
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
        $prompt = htmlspecialchars($prompt, ENT_QUOTES, 'UTF-8');
        $prompt = preg_replace('/[\x00-\x1F\x7F]/', '', $prompt);
        $prompt = strip_tags($prompt);
        $prompt = addslashes($prompt);
        $prompt = preg_replace('/[^a-zA-Z0-9 ]/', '', $prompt);
        return $prompt;
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
        // init client
        $client = $this->getClient();
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
        // init http client and fetch the contextual info
        $httpClient = new \GuzzleHttp\Client();
        $contextUrl = env('OPENAI_PROMPT_CONTEXT_URL');

        if (env('OPENAI_PROMPT_CONTEXT_URL')) {
            $response = $httpClient->request('GET', $contextUrl);
            $context = $response->getBody()->getContents();
            return $context; 
        }

        return null;
    }

    /**
     * Get a response from OpenAI
     * 
     * @param   string $user The name of the user
     * @param   string $prompt The (hopefully sanitized beforehand) prompt
     * 
     * @return  string $result The reply from OpenAI
     */
    public function getResponse($user, $prompt) 
    {
        $client = $this->getClient();
        $context = $this->getContext();
    
        $result = $client->chat()->create([
            'model' => 'gpt-3.5-turbo',
            'messages' => [
                [
                    'role' => 'system',
                    'content' => $context,
                ],
                [
                    'role' => 'user', 
                    'content' => "{$user}: {$prompt}"
                ],
            ],
        ]);
        
        $reply = $result->choices[0]->message->content;

        return $reply;
    }
}