<?php
declare(strict_types=1);

/**
 * Reading the codec out of an MP4, and surviving one that lies.
 *
 * The box trees here are built byte by byte, so the expected codec is known by
 * construction rather than by trusting a sample file somebody committed. The
 * malformed cases matter as much as the valid ones: this parses bytes chosen by
 * whoever uploaded the file, and "it did not hang and did not read the world"
 * is the property worth asserting.
 */
require dirname(__DIR__).'/src/Services/MediaProbe.php';

use CloudHub\Services\MediaProbe;

function rmrf33(string $p): void {
    if (is_link($p) || is_file($p)) { @unlink($p); return; }
    if (is_dir($p)) { foreach (scandir($p) ?: [] as $n) if ($n !== '.' && $n !== '..') rmrf33($p.'/'.$n); @rmdir($p); }
}

/** One ISO-BMFF box: 4-byte big-endian size, 4-byte type, payload. */
function box33(string $type, string $payload): string {
    return pack('N', 8 + strlen($payload)).$type.$payload;
}

/** A stsd holding one sample entry of the given format. */
function stsd33(string $fourcc): string {
    $entry = pack('N', 8).$fourcc.str_repeat("\0", 70);   // size, format, the rest unread
    return box33('stsd', pack('N', 0).pack('N', 1).$entry);
}

/** A minimal but structurally real moov carrying one codec. */
function moov33(string $fourcc): string {
    return box33('moov', box33('trak', box33('mdia', box33('minf', box33('stbl', stsd33($fourcc))))));
}

$base = sys_get_temp_dir().'/cloudhub-p33-'.bin2hex(random_bytes(5));
mkdir($base.'/cache', 0775, true);
$checks = [];
$probe = new MediaProbe($base.'/cache');

/** Write a file and probe it, aging it so the cache rule trusts the result. */
$probeBytes = static function(string $name, string $bytes) use ($base, $probe): array {
    $file = $base.'/'.$name;
    file_put_contents($file, $bytes);
    touch($file, time() - 60);
    clearstatcache(true, $file);
    return $probe->probe($file);
};

// --- the codecs ----------------------------------------------------------

$ftyp = box33('ftyp', 'isom'.pack('N', 512).'isomiso2avc1mp41');

foreach ([
    'avc1' => ['H.264 / AVC', true],
    'hvc1' => ['H.265 / HEVC', false],
    'hev1' => ['H.265 / HEVC', false],
    'av01' => ['AV1', false],
    'vp09' => ['VP9', true],
    'mp4a' => ['AAC', true],
] as $fourcc => [$name, $supported]) {
    $r = $probeBytes($fourcc.'.mp4', $ftyp.moov33($fourcc).box33('mdat', str_repeat("\0", 256)));
    $checks[$fourcc.' is identified'] = ($r['codec'] ?? null) === $fourcc;
    $checks['and named '.$name] = ($r['name'] ?? '') === $name;
    $checks[$fourcc.' support is reported as '.var_export($supported, true)] =
        ($r['widelySupported'] ?? null) === $supported;
}

// The case that started this: an HEVC file must be reportable as such.
$hevc = $probeBytes('holiday.mp4', $ftyp.moov33('hev1').box33('mdat', str_repeat("\0", 4096)));
$checks['an HEVC file is named, not guessed at'] =
    $hevc['name'] === 'H.265 / HEVC' && $hevc['widelySupported'] === false;

// --- moov at the end, as a non-faststart file has it ---------------------

$tail = $probeBytes('tail.mp4', $ftyp.box33('mdat', str_repeat("\0", 200000)).moov33('avc1'));
$checks['a moov after the media data is still found'] = ($tail['codec'] ?? null) === 'avc1';

// --- unknown rather than a guess ------------------------------------------

$weird = $probeBytes('weird.mp4', $ftyp.moov33('zzzz').box33('mdat', 'x'));
$checks['an unrecognised fourcc is reported verbatim, not invented'] =
    $weird['codec'] === 'zzzz' && $weird['widelySupported'] === null;

$notMp4 = $probeBytes('notes.txt', 'this is not a video at all');
$checks['a non-MP4 extension is not parsed hopefully'] = $notMp4['name'] === 'unknown';

$empty = $probeBytes('empty.mp4', '');
$checks['an empty file yields unknown'] = $empty['name'] === 'unknown';

$noMoov = $probeBytes('nomoov.mp4', $ftyp.box33('mdat', str_repeat("\0", 1024)));
$checks['a file with no moov yields unknown'] = $noMoov['name'] === 'unknown';

// --- files that lie -------------------------------------------------------

/*
 * Each of these is malformed in a way that could make a naive walk loop for
 * ever or read far past the file. What is asserted is that the probe returns,
 * quickly, having read a bounded amount -- not what it returns.
 */
$hostile = [
    // A box claiming to be far larger than the file.
    'oversize.mp4' => $ftyp.pack('N', 0x7FFFFFF0).'moov'.str_repeat("\0", 64),
    // A box of declared size 8 containing nothing, repeated: no progress.
    'zerobox.mp4' => $ftyp.str_repeat(pack('N', 8).'free', 512),
    // A size smaller than its own header.
    'tinysize.mp4' => $ftyp.pack('N', 2).'moov'.str_repeat("\0", 64),
    // A 64-bit size with the high word set beyond what PHP can hold.
    'huge64.mp4' => $ftyp.pack('N', 1).'moov'.pack('N', 0xFFFFFFFF).pack('N', 0xFFFFFFFF).str_repeat("\0", 32),
    // Truncated mid-header.
    'truncated.mp4' => $ftyp.substr(box33('moov', stsd33('avc1')), 0, 6),
];
// Deeply nested containers, past the depth limit.
$deep = stsd33('avc1');
for ($i = 0; $i < 40; $i++) $deep = box33('moov', $deep);
$hostile['deep.mp4'] = $ftyp.$deep;

$slowest = 0.0;
$mostRead = 0;
foreach ($hostile as $name => $bytes) {
    $started = microtime(true);
    $r = $probeBytes($name, $bytes);
    $slowest = max($slowest, microtime(true) - $started);
    $mostRead = max($mostRead, $probe->bytesRead());
    $checks[$name.' returns a result instead of hanging'] = isset($r['name']);
}
$checks['no malformed file took more than a second'] = $slowest < 1.0;
$checks['and none read beyond the cap'] = $mostRead <= 4194304;

// --- the cache ------------------------------------------------------------

$cached = $base.'/cachecheck.mp4';
file_put_contents($cached, $ftyp.moov33('hvc1').box33('mdat', str_repeat("\0", 1024)));
touch($cached, time() - 60);
clearstatcache(true, $cached);

$first = $probe->probe($cached);
$firstBytes = $probe->bytesRead();
$second = $probe->probe($cached);
$checks['a cached probe returns the same answer'] = $second === $first;
$checks['and reads nothing the second time'] = $probe->bytesRead() === $firstBytes;
$checks['the cache lives outside the storage root'] = is_file($base.'/cache/media-codecs.json');

// Different bytes under the same name, with a newer mtime, must be re-read.
file_put_contents($cached, $ftyp.moov33('avc1').box33('mdat', str_repeat("\0", 1024)));
touch($cached, time() - 30);
clearstatcache(true, $cached);
$checks['a changed file is probed again'] = ($probe->probe($cached)['codec'] ?? null) === 'avc1';

// --- /play now decides the same way /stream does -------------------------

/*
 * A file whose extension says video but whose bytes libmagic cannot place.
 * /play used to ask mime_type() (libmagic alone) and refuse it with "Media not
 * available", while /api/files/stream would have served it happily via
 * media_mime_type()'s extension map -- which exists precisely because libmagic
 * is unreliable on this platform.
 */
$root = dirname(__DIR__);
$odd = $base.'/odd.mp4';
file_put_contents($odd, str_repeat("\x00\x11\x22\x33", 64));   // valid extension, unhelpful bytes
$detected = function_exists('mime_content_type') ? (string)@mime_content_type($odd) : '';
$checks['the fixture is one libmagic does not call video'] = !str_starts_with($detected, 'video/');

$index = (string)file_get_contents($root.'/public/index.php');
$playRoute = (static function(string $i): string {
    $at = strpos($i, "if (\$path === '/play' && \$method === 'GET')");
    return $at === false ? '' : substr($i, $at, 2000);
})($index);
$checks['/play asks the extension-first mapper'] =
    $playRoute !== '' && str_contains($playRoute, '$mime = media_mime_type($f);');
$checks['and no longer asks libmagic alone'] =
    $playRoute !== '' && !str_contains($playRoute, '$mime = mime_type($f);');

// The two now genuinely agree on this file.
require_once $root.'/src/Services/FileService.php';
$probeFns = (static function(string $i): string {
    $out = '';
    foreach (['mime_type', 'media_mime_type'] as $fn) {
        $at = strpos($i, 'function '.$fn.'(string $f): string {');
        if ($at === false) continue;
        $open = strpos($i, '{', $at);
        $depth = 0;
        for ($k = $open; $k < strlen($i); $k++) {
            if ($i[$k] === '{') $depth++;
            elseif ($i[$k] === '}') { $depth--; if ($depth === 0) { $out .= substr($i, $at, $k - $at + 1)."\n"; break; } }
        }
    }
    return $out;
})($index);
$agreeProbe = $base.'/agree.php';
file_put_contents($agreeProbe, "<?php\n".$probeFns."\necho media_mime_type(\$argv[1]);\n");
$served = trim((string)shell_exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg($agreeProbe).' '.escapeshellarg($odd).' 2>/dev/null'));
$checks['the extension mapper calls it video/mp4'] = $served === 'video/mp4';

// --- the player and the listing use what the probe found -----------------

$player = (string)file_get_contents($root.'/public/assets/js/player/PlayerUI.js');
$checks['the player names the codec when it cannot decode'] =
    str_contains($player, 'This browser cannot decode ${media.codec}');
$checks['it treats both decode errors the same way'] =
    str_contains($player, 'code === MediaError.MEDIA_ERR_DECODE')
    && str_contains($player, 'code === MediaError.MEDIA_ERR_SRC_NOT_SUPPORTED');
$checks['it offers a download instead of a dead end'] =
    str_contains($player, "link.setAttribute('download', '')");
// The message can carry a filename, so it must not be built as markup.
$checks['the status is set as text, not HTML'] =
    str_contains($player, 'status.textContent = message;') && !str_contains($player, 'status.innerHTML');

$play = (string)file_get_contents($root.'/views/pages/play.php');
$checks['the page passes the codec to the player'] = str_contains($play, 'window.CLOUDHUB_MEDIA');
$checks['and a download URL with it'] = str_contains($play, "'downloadUrl' => \$mediaFile['download_url'] ?? null");

$app = (string)file_get_contents($root.'/public/assets/js/app.js');
$checks['the listing marks only what the browser itself refused'] =
    str_contains($app, 'const code = video.error?.code;')
    && str_contains($app, 'markUndecodable(button, status);');
$checks['and the mark never hides or disables the card'] =
    str_contains($app, "button.classList.add('undecodable');")
    && !str_contains($app, "button.disabled = true")
    && !str_contains($app, "card.hidden = true");

rmrf33($base);

$bad = false;
foreach ($checks as $name => $ok) {
    echo ($ok ? '[PASS] ' : '[FAIL] ').$name.PHP_EOL;
    $bad = $bad || !$ok;
}
exit($bad ? 1 : 0);
