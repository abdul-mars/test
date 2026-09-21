<?php
declare(strict_types=1);

/**
 * Front controller. Everything enters here.
 */

/**
 * PHP's built-in server routes every request through this script, including
 * ones for files that exist on disk. Returning false hands those back to the
 * server to serve directly, so `php -S` behaves like nginx's try_files.
 * Production front ends never reach this branch.
 */
if (PHP_SAPI === 'cli-server') {
    $path = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
    $path = is_string($path) ? rawurldecode($path) : '/';
    $candidate = realpath(__DIR__ . $path);
    // realpath() resolves any "..", so a traversal cannot escape public/.
    if ($candidate !== false
        && is_file($candidate)
        && str_starts_with($candidate, __DIR__ . DIRECTORY_SEPARATOR)
        && !str_ends_with($candidate, '.php')) {
        return false;
    }
}

require __DIR__ . '/../src/bootstrap.php';

use QRoute\App;
use QRoute\Http\Request;

$request = Request::capture();
$response = (new App($request))->run();

// Content-Length is set explicitly so a HEAD response advertises the size
// of the body a GET would have returned.
if (!isset($response->headers['Content-Length']) && $response->body !== '') {
    $response->headers['Content-Length'] = (string) strlen($response->body);
}

$response->send($request->method !== 'HEAD');
