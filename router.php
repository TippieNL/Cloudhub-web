<?php
declare(strict_types=1);

// Router for PHP's built-in development server, started from the project root:
//   php -S 0.0.0.0:8000 router.php
//
// Here the project root is the document root, so pages request static files as
// /public/assets/... (see Http::assetBase()). Apache's .htaccess additionally
// aliases /assets/... and /favicon.png into public/; mirror both spellings so
// the development server behaves like the deployed one.

$uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

// The built-in server does not read .htaccess, so mirror its deny rules here.
// Without this the project root — which is the document root in this layout —
// hands out .env, the database schema and the PHP sources verbatim.
$denied = '#^/(?:config|src|views|database|storage|logs|tests|tools|deploy)(?:/|$)'
    .'|^/\.env|^/(?:README|SECURITY)\.md$'
    .'|\.(?:bak|old|orig|save|sql|log|ini|dist)$#i';
if (preg_match($denied, $uri)) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Forbidden\n";
    return true;
}

if ($uri !== '/') {
    // A real file below the project root, e.g. /public/assets/js/app.js.
    // Returning false lets the built-in server stream (or execute) it.
    if (is_file(__DIR__.$uri)) return false;

    // Alias: /assets/... and /favicon.png live under public/.
    $publicDir = realpath(__DIR__.'/public');
    $aliased = realpath(__DIR__.'/public'.$uri);
    if ($publicDir !== false && $aliased !== false && is_file($aliased)
        && str_starts_with($aliased, $publicDir.DIRECTORY_SEPARATOR)
        && strtolower((string)pathinfo($aliased, PATHINFO_EXTENSION)) !== 'php') {
        header('Content-Type: '.(mime_content_type($aliased) ?: 'application/octet-stream'));
        header('Content-Length: '.filesize($aliased));
        readfile($aliased);
        return true;
    }
}

// Otherwise, boot the application.
require __DIR__ . '/public/index.php';
