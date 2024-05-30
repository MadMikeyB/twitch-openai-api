<?php

require __DIR__ . '/bootstrap.php';

if (!isset($_GET['prompt'])) {
    echo '<form action="index.php" method="GET">';
    echo '<div><label for="user">Username</label><input type="text" name="user" id="user"></div>';
    echo '<div><label for="prompt">Message</label><input type="text" name="prompt" id="prompt"></div>';
    echo '<div><input type="submit"></div>';
    echo '</form>';
} else {
    if ($_GET['prompt'])
    {
        if (isset($_GET['user'])) {
            $user = $_GET['user'];
        } else {
            $user = "AnAnonymousChatter";
        }

        $log->info('RAW PROMPT:'. $_GET['prompt']);
        $gpt = new \TwitchGpt\Services\Gpt;
        // $prompt = $gpt->mergePromptWithContext($_GET['prompt']);
        $prompt = $gpt->cleanPrompt($_GET['prompt']);
        $log->info('Prompt input:'. $prompt);
        $response = $gpt->getResponse($user, $prompt);
        $log->info('Response:'. $response);
        echo $response;
    }
}