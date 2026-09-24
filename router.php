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

/*
 * Public share links, before the deny rules.
 *
 * A share URL ends in the shared file's extension, and the rules below refuse
 * anything ending .log, .sql, .ts and friends -- so a shared notes.log, or an
 * MPEG-TS clip.ts, would 403 here and under Apache alike. /share/... never
 * names a file under the project root, so there is nothing here for those
 * rules to protect.
 */
if (preg_match('#^/share/[A-Za-z0-9_-]{20,128}(?:[./]|$)#', $uri)) {
    require __DIR__ . '/public/index.php';
    return true;
}

// The built-in server does not read .htaccess, so mirror its deny rules here.
// Without this the project root — which is the document root in this layout —
// hands out .env, the database schema and the PHP sources verbatim.
//
// Any dot-segment is refused (/.git/config, /.env, /.agents, editor
// droppings) except /.well-known/, which ACME certificate renewal answers
// from, along with the Node/React source and build trees this PHP port sits
// beside -- client/, server/, shared/, script/, node_modules/ and the tool
// configs -- none of which PHP serves. vendor/ is the bundled phpFastCache,
// and composer.json/.lock say which versions of it are here. The extension
// list catches loose artefacts; /share/ was exempted above.
$denied = '#(?:^|/)\.(?!well-known(?:/|$))'
    .'|^/(?:config|src|views|database|storage|logs|tests|tools|deploy|vendor'
    .'|client|server|shared|script|node_modules|attached_assets|\.agents)(?:/|$)'
    .'|^/(?:README|SECURITY|PROJECT_CONTEXT|replit)\.md$'
    .'|^/(?:composer\.(?:json|lock)|package(?:-lock)?\.json|drizzle\.config\.ts|components\.json|skills-lock\.json'
    .'|tsconfig\.json|vite\.config\.ts|tailwind\.config\.ts|postcss\.config\.js)$'
    .'|\.(?:bak|old|orig|save|sql|log|ini|dist|ts|tsx)$#i';
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
