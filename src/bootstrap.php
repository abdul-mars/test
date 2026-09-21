<?php
declare(strict_types=1);

/**
 * Application bootstrap: autoloader, configuration, error handling.
 * Included by both the web front controller and the CLI console.
 */

const QROUTE_ROOT = __DIR__ . '/..';

spl_autoload_register(static function (string $class): void {
    $prefix = 'QRoute\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $relative = substr($class, strlen($prefix));
    $path = __DIR__ . '/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($path)) {
        require $path;
    }
});

\QRoute\Core\Config::load(QROUTE_ROOT . '/.env');

date_default_timezone_set(\QRoute\Core\Config::get('APP_TIMEZONE', 'UTC') ?? 'UTC');
mb_internal_encoding('UTF-8');

$debug = \QRoute\Core\Config::bool('APP_DEBUG', false);
error_reporting(E_ALL);
ini_set('display_errors', $debug ? '1' : '0');
ini_set('log_errors', '1');

// Never let PHP guess: uploaded content and generated output are always
// declared explicitly by the response layer.
ini_set('default_charset', 'UTF-8');
ini_set('zend.exception_ignore_args', $debug ? '0' : '1');
