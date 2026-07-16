<?php
/**
 * Bootstrap our app
 */

 require __DIR__ . '/vendor/autoload.php';

foreach (['cache', 'logs'] as $directory) {
    $path = __DIR__ . '/' . $directory;

    if (!is_dir($path)) {
        mkdir($path, 0775, true);
    }
}

 /**
  * Load environment file
  */
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
$dotenv->safeLoad();

/**
 * Load error handler
 */
\Spatie\Ignition\Ignition::make()
    ->applicationPath(__DIR__ . '/app')
    ->register();

/**
 * Load logger
 */

$log = new \Monolog\Logger('twitch-gpt');
$log->pushHandler(new \Monolog\Handler\StreamHandler(__DIR__ . '/logs/twitch-gpt-' . date('y-m-d') . '.log', \Monolog\Level::Info));
$log->info('Init logging');
