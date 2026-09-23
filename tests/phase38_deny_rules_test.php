<?php
declare(strict_types=1);

/**
 * router.php's deny rules, over real HTTP.
 *
 * The built-in server does not read .htaccess, so router.php mirrors its rules
 * -- and in the project-root layout it is all that stands between a browser
 * and .env, .git and the PHP sources. This runs the real router behind
 * `php -S` in a scratch copy of the layout, with a stub application, so what
 * is asserted is what a browser gets.
 *
 * Pins: every dot-segment is refused except /.well-known/ (ACME HTTP-01
 * renewal answers from it); internals and loose artefacts are refused; and a
 * share link reaches the application whatever extension the shared file has,
 * because a share URL ends in the file's own name or extension.
 */
$root = dirname(__DIR__);
$checks = [];
$tmp = sys_get_temp_dir().'/cloudhub-p38-'.bin2hex(random_bytes(5));
$put = static function (string $rel, string $body) use ($tmp): void {
    @mkdir(dirname($tmp.'/'.$rel), 0775, true);
    file_put_contents($tmp.'/'.$rel, $body);
};
mkdir($tmp, 0775, true);
copy($root.'/router.php', $tmp.'/router.php');
$put('public/index.php', '<?php echo "APP ", parse_url($_SERVER["REQUEST_URI"], PHP_URL_PATH);');
$put('public/assets/js/app.js', 'console.log(1);');
$put('.well-known/acme-challenge/tok', 'acme');
$put('.git/config', '[core]');
$put('.env', 'DB_PASS=secret');
$put('.gitignore', '/storage');
$put('docs/.hidden', 'x');
$put('src/Services/Auth.php', '<?php');
$put('notes.log', 'log');
$put('database/schema.sql', 'CREATE');

$index = (string)file_get_contents($root.'/public/index.php');
$token = str_repeat('A', 43);
// The share URL shapes this build hands out: Cloudhub-web puts the file's
// extension on the token, Cloudhub-2 appends the file's name as a segment.
$shareUrls = str_contains($index, 'function share_url_suffix(')
    ? ['/share/'.$token, '/share/'.$token.'.ts', '/share/'.$token.'.log', '/share/'.$token.'/raw.sql', '/share/'.$token.'.png/download.ini']
    : ['/share/'.$token, '/share/'.$token.'/clip.ts', '/share/'.$token.'/notes.log', '/share/'.$token.'/raw/setup.ini', '/share/'.$token.'/download/dump.sql'];

$port = 8600 + random_int(0, 300);
$server = proc_open([PHP_BINARY, '-S', '127.0.0.1:'.$port, $tmp.'/router.php'],
    [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, $tmp);
$base = 'http://127.0.0.1:'.$port;
for ($i = 0; $i < 200; $i++) { if (@file_get_contents($base.'/public/assets/js/app.js') !== false) break; usleep(25000); }

/** @return array{0:int,1:string} */
$get = static function (string $path) use ($base): array {
    $body = @file_get_contents($base.$path, false, stream_context_create(['http' => ['ignore_errors' => true, 'timeout' => 10]]));
    preg_match('~HTTP/\S+\s+(\d+)~', implode("\n", $http_response_header ?? []), $m);
    return [(int)($m[1] ?? 0), (string)$body];
};

foreach (['/.git/config', '/.env', '/.gitignore', '/docs/.hidden', '/src/Services/Auth.php', '/notes.log', '/database/schema.sql'] as $path) {
    [$status] = $get($path);
    $checks["$path is refused"] = $status === 403;
}
[$status, $body] = $get('/.well-known/acme-challenge/tok');
$checks['an ACME challenge is still served'] = $status === 200 && $body === 'acme';
[$status, $body] = $get('/public/assets/js/app.js');
$checks['a public asset is still served'] = $status === 200 && $body === 'console.log(1);';
foreach ($shareUrls as $url) {
    [$status, $body] = $get($url);
    $checks['share link '.(substr($url, strlen('/share/'.$token)) ?: '(bare token)').' reaches the application'] =
        $status === 200 && $body === 'APP '.$url;
}

if (is_resource($server)) { proc_terminate($server); proc_close($server); }
$rmrf = static function (string $p) use (&$rmrf): void {
    if (is_link($p) || is_file($p)) { @unlink($p); return; }
    if (is_dir($p)) { foreach (scandir($p) ?: [] as $n) if ($n !== '.' && $n !== '..') $rmrf($p.'/'.$n); @rmdir($p); }
};
$rmrf($tmp);

// .htaccess cannot run here; pin that it carries the same two exemptions.
$htaccess = (string)file_get_contents($root.'/.htaccess');
$checks['.htaccess exempts /.well-known/ from the dot rule'] = str_contains($htaccess, 'RewriteRule (?:^|/)\.(?!well-known(?:/|$)) - [F,L]');
$checks['.htaccess routes share links before any deny rule'] =
    (bool)preg_match('~^RewriteRule \^share/~m', $htaccess)
    && strpos($htaccess, 'RewriteRule ^share/') < strpos($htaccess, '[F,L');

$bad = false;
foreach ($checks as $name => $ok) { echo ($ok ? '[PASS] ' : '[FAIL] ').$name.PHP_EOL; $bad = $bad || !$ok; }
exit($bad ? 1 : 0);
