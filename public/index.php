<?php

require __DIR__ . '/../bootstrap.php';

function finishResponseAndContinue()
{
    ignore_user_abort(true);

    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
        return;
    }

    if (ob_get_level() > 0) {
        ob_end_flush();
    }

    flush();
}

if (!isset($_GET['prompt'])) {
    echo '<form action="index.php" method="GET">';
    echo '<div><label for="user">Username</label><input type="text" name="user" id="user"></div>';
    echo '<div><label for="prompt">Message</label><input type="text" name="prompt" id="prompt"></div>';
    echo '<div><input type="submit"></div>';
    echo '</form>';
} else {
    if ($_GET['prompt'])
    {
        $requestStartedAt = microtime(true);

        if (isset($_GET['user'])) {
            $user = $_GET['user'];
        } else {
            $user = "AnAnonymousChatter";
        }

        $log->info('RAW PROMPT:'. $_GET['prompt']);
        $gpt = new \TwitchGpt\Services\Gpt;
        $useWebSearch = $gpt->shouldUseWebSearch($_GET['prompt']);
        $promptWithoutSearchTrigger = $gpt->stripWebSearchTrigger($_GET['prompt']);
        $prompt = $gpt->cleanPrompt($promptWithoutSearchTrigger);
        $nightbotResponseUrl = $_SERVER['HTTP_NIGHTBOT_RESPONSE_URL'] ?? null;
        $log->info('Prompt input:'. $prompt);

        if ($useWebSearch && $nightbotResponseUrl) {
            $acknowledgement = 'Searching...';
            header('Content-Type: text/plain; charset=utf-8');
            echo $acknowledgement;
            $log->info('Deferred search response started');

            finishResponseAndContinue();

            try {
                $response = $gpt->getResponse($user, $prompt, true);
                $timings = $gpt->getTimings();
                $log->info('OpenAI meta: ' . json_encode([
                    'cache_hit' => $timings['openai']['cache_hit'] ?? false,
                    'memory_entries_sent' => $timings['memory']['entries_sent'] ?? 0,
                ]));
                $log->info('Timings: ' . json_encode($timings));

                $httpClient = new \GuzzleHttp\Client();
                $httpClient->request('POST', $nightbotResponseUrl, [
                    'form_params' => [
                        'message' => $response,
                    ],
                ]);

                $log->info('Deferred Nightbot response sent:'. $response);
            } catch (\Throwable $exception) {
                $log->warning('Deferred Nightbot response failed: ' . $exception->getMessage());
            }

            return;
        }

        $response = $gpt->getResponse($user, $prompt, $useWebSearch);
        $timings = $gpt->getTimings();
        $totalRequestDurationMs = (int) round((microtime(true) - $requestStartedAt) * 1000);
        $timings['request'] = ['duration_ms' => $totalRequestDurationMs];
        header('X-Twitch-Gpt-Response-Time-Ms: ' . $totalRequestDurationMs);
        $log->info('OpenAI meta: ' . json_encode([
            'cache_hit' => $timings['openai']['cache_hit'] ?? false,
            'memory_entries_sent' => $timings['memory']['entries_sent'] ?? 0,
        ]));
        $log->info('Timings: ' . json_encode($timings));
        $log->info('Response:'. $response);
        echo $response;
    }
}
