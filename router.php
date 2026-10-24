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

/**
 * Tell the application which script it is really running.
 *
 * PHP's built-in server (8.2 and 8.3.6 alike, measured) hands a request whose
 * path ends in an extension -- /share/TOKEN.jpg, /webdav/Photos/beach.jpg --
 * to this router with SCRIPT_NAME set to that path and SCRIPT_FILENAME naming
 * this file, as if the URL named a script. Http::basePath() reads the install
 * folder from SCRIPT_NAME, so it took "/share" or "/webdav/Photos" for one and
 * stripped it: every share link carrying its file's extension, and every
 * WebDAV file, answered "Not found" here while working under Apache and nginx,
 * which report the front controller. Under this router the application sits at
 * the root of the server, so that is what it is told -- in the form the server
 * itself uses for any other path, and only when it has named the router.
 * (Cloudhub-2's router does the same.)
 */
function dev_front_controller(string $uri): void
{
    if (realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? '')) !== __FILE__) return;
    $_SERVER['SCRIPT_FILENAME'] = rtrim((string)($_SERVER['DOCUMENT_ROOT'] ?? __DIR__), '/').'/index.php';
    $_SERVER['SCRIPT_NAME'] = '/index.php';
    $_SERVER['PHP_SELF'] = '/index.php'.$uri;
}

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
    dev_front_controller($uri);
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
dev_front_controller($uri);
require __DIR__ . '/public/index.php';
