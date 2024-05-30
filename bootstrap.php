<?php
/**
 * Bootstrap our app
 */

 require __DIR__ . '/vendor/autoload.php';

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
$log->pushHandler(new \Monolog\Handler\StreamHandler('logs/twitch-gpt-' . date('y-m-d') . '.log', \Monolog\Level::Warning));
$log->info('Init logging');