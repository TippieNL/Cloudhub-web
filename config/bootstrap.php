<?php
declare(strict_types=1);

/**
 * Map CloudHub\Foo\Bar to src/Foo/Bar.php.
 *
 * A missing file is reported here, naming the path that was tried, rather
 * than being skipped so PHP can raise "Class not found" further down. Those
 * two failures have the same cause and wildly different diagnostic value: on
 * a deployment where src/ had not been copied across, the silent version cost
 * an evening, because the message named a class that was never the problem
 * and no path at all.
 *
 * Only classes under the CloudHub prefix are our business; anything else is
 * left for the next registered autoloader, which is why class_exists() probes
 * for optional third-party classes still work.
 */
spl_autoload_register(function(string $class): void {
    $prefix = 'CloudHub\\';
    if (!str_starts_with($class, $prefix)) return;
    $relative = str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    $file = dirname(__DIR__) . '/src/' . $relative;
    if (!is_file($file)) {
        throw new RuntimeException(
            'Cannot load '.$class.': '.$file.' is missing or unreadable. '
            .'The application directory looks incomplete -- check that src/ was '
            .'deployed in full and that the web server user can read it.'
        );
    }
    require $file;
});

function load_env(string $file): void {
    if (!is_file($file)) return;
    foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) continue;
        [$k,$v] = array_map('trim', explode('=', $line, 2));
        $v = trim($v, "\"'");
        if (getenv($k) === false) { putenv("$k=$v"); $_ENV[$k] = $v; }
    }
}
load_env(dirname(__DIR__) . '/.env');

function env(string $key, mixed $default=null): mixed { $v=getenv($key); return $v===false ? $default : $v; }
function env_bool(string $key, bool $default=false): bool { $v=env($key, $default?'true':'false'); return filter_var($v, FILTER_VALIDATE_BOOLEAN); }

$config = require __DIR__ . '/config.php';
date_default_timezone_set('UTC');
$isDevelopment=$config['app_env']==='development';
ini_set('display_errors',$isDevelopment?'1':'0');
ini_set('display_startup_errors',$isDevelopment?'1':'0');
ini_set('log_errors','1');
ini_set('expose_php','0');
error_reporting(E_ALL);
set_exception_handler(function(Throwable $e) use ($config): void {
    error_log($e->__toString());
    if (!headers_sent()) http_response_code(500);
    $errorRoute = isset($_GET['route']) && is_string($_GET['route']) ? $_GET['route'] : ($_SERVER['REQUEST_URI'] ?? '');
    if (str_starts_with($errorRoute, '/api/')) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success'=>false,'error'=>['code'=>'INTERNAL_ERROR','message'=>$config['app_env']==='development'?$e->getMessage():'Internal server error']]);
    } else echo $config['app_env']==='development' ? '<pre>'.htmlspecialchars((string)$e).'</pre>' : 'Internal server error';
});
