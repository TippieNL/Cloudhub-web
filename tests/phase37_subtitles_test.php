<?php
declare(strict_types=1);

/**
 * Subtitles that sit beside a video: which files are claimed, and what comes
 * back when one is played.
 *
 * The discovery half runs against a real storage root rather than a mocked
 * one, because the interesting cases are all filesystem shapes -- a `Subs/`
 * folder beside two films, a name that merely starts the same, a symlink. The
 * conversion half is bytes in and bytes out, including the two that break a
 * naive implementation: a comma where WebVTT wants a dot, and an .srt written
 * on Windows that is not UTF-8 at all.
 */
$root = dirname(__DIR__);
require $root.'/src/Services/FileService.php';
require $root.'/src/Services/SubtitleService.php';

use CloudHub\Services\FileService;
use CloudHub\Services\SubtitleService;

function rmrfSubs(string $p): void {
    if (is_link($p) || is_file($p)) { @unlink($p); return; }
    if (is_dir($p)) { foreach (scandir($p) ?: [] as $n) if ($n !== '.' && $n !== '..') rmrfSubs($p.'/'.$n); @rmdir($p); }
}

$base = sys_get_temp_dir().'/cloudhub-subs-'.bin2hex(random_bytes(5));
mkdir($base.'/Film/Subs/Holiday', 0775, true);
mkdir($base.'/Season', 0775, true);

$write = static function(string $path, string $body) use ($base): void {
    file_put_contents($base.'/'.$path, $body);
};

$checks = [];
$fs = new FileService(['root_dir' => $base, 'read_only' => false]);
$service = new SubtitleService($fs);

/* --- what belongs to which video ------------------------------------------ */

$write('Film/Holiday.mp4', 'not really a video');
$write('Film/Holiday.srt', "1\n00:00:01,000 --> 00:00:02,000\nPlain\n");
$write('Film/Holiday.en.srt', "1\n00:00:01,000 --> 00:00:02,000\nEnglish\n");
$write('Film/Holiday.nl.forced.srt', "1\n00:00:01,000 --> 00:00:02,000\nForced\n");
$write('Film/Holiday.en.sdh.vtt', "WEBVTT\n\n00:00:01.000 --> 00:00:02.000\nSDH\n");
// Starts with the same letters but is a different film.
$write('Film/Holiday 2.srt', "1\n00:00:01,000 --> 00:00:02,000\nOther film\n");
// Not a subtitle, and a subtitle with nothing in it.
$write('Film/Holiday.txt', 'notes');
$write('Film/Holiday.de.srt', '');

$tracks = $service->tracksFor('/Film/Holiday.mp4');
$byLabel = [];
foreach ($tracks as $track)$byLabel[$track['label']] = $track;

$checks['a sidecar named after the video is found'] = isset($byLabel['Subtitles']);
$checks['a language tag becomes a language and a label'] =
    ($byLabel['English']['language'] ?? null) === 'en'
    && ($byLabel['Dutch (Forced)']['language'] ?? null) === 'nl';
$checks['forced and SDH are read off the name'] =
    ($byLabel['Dutch (Forced)']['forced'] ?? null) === true
    && ($byLabel['English (SDH)']['hearingImpaired'] ?? null) === true
    && ($byLabel['English']['forced'] ?? null) === false;
$checks['both sidecar formats are offered'] =
    ($byLabel['English (SDH)']['format'] ?? null) === 'vtt'
    && ($byLabel['English']['format'] ?? null) === 'srt';
// "Holiday 2.srt" is the subtitle of a different film that happens to sort
// next to this one; claiming it would put someone else's dialogue on screen.
$checks['a name that merely starts the same is not claimed'] =
    !in_array('/Film/Holiday 2.srt', array_column($tracks, 'path'), true);
$checks['a file that is not a subtitle is not offered'] =
    !in_array('/Film/Holiday.txt', array_column($tracks, 'path'), true);
$checks['an empty subtitle file is not offered'] =
    !in_array('/Film/Holiday.de.srt', array_column($tracks, 'path'), true);
$checks['every track carries a stable id and its own path'] =
    count(array_unique(array_column($tracks, 'id'))) === count($tracks)
    && $tracks[0]['id'] === $service->tracksFor('/Film/Holiday.mp4')[0]['id'];

/* --- the Subs/ folder ripping tools leave behind --------------------------- */

$write('Film/Subs/English.srt', "1\n00:00:01,000 --> 00:00:02,000\nFrom Subs\n");
$write('Film/Subs/Holiday/Dutch.srt', "1\n00:00:01,000 --> 00:00:02,000\nFrom Subs/Holiday\n");

$paths = array_column($service->tracksFor('/Film/Holiday.mp4'), 'path');
$checks['a Subs folder beside a single film is claimed'] =
    in_array('/Film/Subs/English.srt', $paths, true);
$checks['a Subs folder named after the film is claimed'] =
    in_array('/Film/Subs/Holiday/Dutch.srt', $paths, true);

// Two films in the folder: "English.srt" no longer says which one it belongs
// to, and guessing would put the same subtitles on every episode.
$write('Season/One.mkv', 'not really a video');
$write('Season/Two.mkv', 'not really a video');
mkdir($base.'/Season/Subs', 0775, true);
$write('Season/Subs/English.srt', "1\n00:00:01,000 --> 00:00:02,000\nAmbiguous\n");
$write('Season/One.en.srt', "1\n00:00:01,000 --> 00:00:02,000\nEpisode one\n");

$seasonPaths = array_column($service->tracksFor('/Season/One.mkv'), 'path');
$checks['a shared Subs folder is not spread over every episode'] =
    !in_array('/Season/Subs/English.srt', $seasonPaths, true);
$checks['an episode still gets the sidecar named after it'] =
    $seasonPaths === ['/Season/One.en.srt'];

/* --- names that read like a language but are not --------------------------- */

$write('Film/Holiday.hi.srt', "1\n00:00:01,000 --> 00:00:02,000\nHindi\n");
$hindi = null;
foreach ($service->tracksFor('/Film/Holiday.mp4') as $track) {
    if ($track['path'] === '/Film/Holiday.hi.srt') $hindi = $track;
}
// "hi" is Hindi on its own and "hearing impaired" after a language, which is
// the difference between a menu that says Hindi and one that does not.
$checks['hi alone is Hindi, not hearing-impaired'] =
    $hindi !== null && $hindi['language'] === 'hi' && $hindi['hearingImpaired'] === false;

/* --- a video with nothing beside it ---------------------------------------- */

$write('Film/Alone.mp4', 'not really a video');
$checks['a video with no subtitles answers with no tracks'] = $service->tracksFor('/Film/Alone.mp4') === [];
$checks['a path that does not exist answers with no tracks'] = $service->tracksFor('/Film/Missing.mp4') === [];
$checks['a path outside the storage root answers with no tracks'] = $service->tracksFor('/../etc/passwd') === [];

/* --- SubRip to WebVTT ------------------------------------------------------ */

$srt = "\xEF\xBB\xBF1\r\n00:00:01,000 --> 00:00:02,500\r\nFirst line\r\nSecond line\r\n\r\n"
    ."2\r\n00:01:02,5 --> 01:02:03,456\r\n<i>Italic</i> <font color=\"#ff0000\">red</font>\r\n"
    ."{\\an8}Top of screen\r\n";
$write('Film/Holiday.mp4.tmp', $srt);   // written, then renamed, so the name is exact
rename($base.'/Film/Holiday.mp4.tmp', $base.'/Film/Convert.srt');
$vtt = $service->webVtt('/Film/Convert.srt');

$checks['the conversion announces itself as WebVTT'] = str_starts_with($vtt, "WEBVTT\n\n");
$checks['commas become dots and every stamp is full width'] =
    str_contains($vtt, '00:00:01.000 --> 00:00:02.500')
    && str_contains($vtt, '00:01:02.500 --> 01:02:03.456');
$checks['the cue numbers are gone'] = !preg_match('/^\d+$/m', $vtt);
$checks['a cue keeps both of its lines'] = str_contains($vtt, "First line\nSecond line");
$checks['italics survive and colours do not'] =
    str_contains($vtt, '<i>Italic</i>') && !str_contains($vtt, 'font');
$checks['positioning overrides are dropped, not shown'] =
    str_contains($vtt, 'Top of screen') && !str_contains($vtt, '{\\an8}');
$checks['the BOM does not reach the browser'] = !str_contains($vtt, "\xEF\xBB\xBF");

// A subtitle is text somebody else wrote. Markup inside it is text too.
$write('Film/Hostile.srt', "1\n00:00:01,000 --> 00:00:02,000\n<script>alert(1)</script> & co\n");
$hostile = $service->webVtt('/Film/Hostile.srt');
$checks['markup inside a cue arrives as text'] =
    str_contains($hostile, '&lt;script&gt;') && !str_contains($hostile, '<script>')
    && str_contains($hostile, '&amp; co');

/* --- encodings ------------------------------------------------------------- */

// Windows-1252, which is what a subtitle typed on Windows usually is. Served
// as UTF-8 unchanged, every accented character becomes a replacement glyph.
$write('Film/Latin.srt', "1\n00:00:01,000 --> 00:00:02,000\nCaf\xE9 cr\xE8me\n");
$checks['a Windows-1252 subtitle arrives readable'] =
    str_contains($service->webVtt('/Film/Latin.srt'), 'Café crème');
$write('Film/Utf8.srt', "1\n00:00:01,000 --> 00:00:02,000\nCafé crème\n");
$checks['a UTF-8 subtitle is left alone'] =
    str_contains($service->webVtt('/Film/Utf8.srt'), 'Café crème');
$write('Film/Utf16.srt', "\xFF\xFE".mb_convert_encoding(
    "1\n00:00:01,000 --> 00:00:02,000\nCafé\n", 'UTF-16LE', 'UTF-8'));
$checks['a UTF-16 subtitle arrives readable'] =
    str_contains($service->webVtt('/Film/Utf16.srt'), 'Café');

/* --- files that are already WebVTT ----------------------------------------- */

$write('Film/Real.vtt', "WEBVTT\n\nNOTE this is a comment\n\ncue-1\n00:00:01.000 --> 00:00:02.000 align:start\nAs written\n");
$real = $service->webVtt('/Film/Real.vtt');
$checks['a real WebVTT file keeps its header once'] = substr_count($real, 'WEBVTT') === 1;
$checks['cue settings survive'] = str_contains($real, '00:00:01.000 --> 00:00:02.000 align:start');
// Renaming an .srt to .vtt is common and produces a file browsers reject.
$write('Film/Liar.vtt', "1\n00:00:01,000 --> 00:00:02,000\nActually SubRip\n");
$liar = $service->webVtt('/Film/Liar.vtt');
$checks['an .srt named .vtt is repaired rather than refused'] =
    str_starts_with($liar, "WEBVTT\n\n") && str_contains($liar, '00:00:01.000 --> 00:00:02.000');
// X1/Y1 coordinates are SubRip's own, and mean nothing to WebVTT.
$write('Film/Coords.srt', "1\n00:00:01,000 --> 00:00:02,000  X1:100 X2:200\nPlaced\n");
$checks['SubRip coordinates are not passed off as cue settings'] =
    str_contains($service->webVtt('/Film/Coords.srt'), "00:00:01.000 --> 00:00:02.000\nPlaced");

/* --- what the route will not serve ----------------------------------------- */

$refused = static function(callable $fn): int {
    try { $fn(); return 0; }catch(RuntimeException $e) { return (int)$e->getCode(); }
};
$checks['a file that is not a subtitle is refused as 415'] =
    $refused(fn() => $service->webVtt('/Film/Holiday.mp4')) === 415;
$checks['a missing file is refused as 404'] =
    $refused(fn() => $service->webVtt('/Film/Nothing.srt')) === 404;
$write('Film/Huge.srt', str_repeat('x', 4194305));
$checks['a subtitle larger than the cap is refused, not read'] =
    $refused(fn() => $service->webVtt('/Film/Huge.srt')) === 413;
$checks['path traversal is refused, not followed'] =
    $refused(fn() => $service->webVtt('/../../etc/passwd')) >= 400;

/* --- symlinks -------------------------------------------------------------- */

if (@symlink($base.'/Film/Holiday.en.srt', $base.'/Film/Alone.en.srt')) {
    // The storage root is what may be served. A link is an invitation to leave
    // it, and FileService refuses one everywhere else too.
    $checks['a symlinked sidecar is not offered'] = $service->tracksFor('/Film/Alone.mp4') === [];
}

/* --- the wiring, pinned over the source ------------------------------------ */

$read = fn(string $path): string => (string)@file_get_contents($root.'/'.$path);
$index = $read('public/index.php');
$play = $read('views/pages/play.php');
$playerUi = $read('public/assets/js/player/PlayerUI.js');
$manager = $read('public/assets/js/player/SubtitleManager.js');
$parser = $read('public/assets/js/player/VTTParser.js');
$keys = $read('public/assets/js/player/KeyboardShortcuts.js');
$css = $read('public/assets/css/player.css');

$checks['both routes exist and only answer reads'] =
    str_contains($index, "if (\$path === '/api/files/subtitles' && \$method === 'GET')")
    && str_contains($index, "if (\$path === '/api/files/subtitle' && (\$method === 'GET' || \$method === 'HEAD'))");
$checks['a track is served as WebVTT, and not sniffed as anything else'] =
    str_contains($index, "header('Content-Type: text/vtt; charset=utf-8');")
    && str_contains($index, "header('X-Content-Type-Options: nosniff');");
$checks['a HEAD asks the same question without the body'] =
    str_contains($index, "if (\$method !== 'HEAD')echo \$vtt;");
$checks['the player page is rendered with the tracks it needs'] =
    str_contains($index, "'subtitles' => subtitle_tracks(\$frontController, \$relPath)")
    && str_contains($play, 'window.CLOUDHUB_SUBTITLES');
// Labels are built from filenames, and a filename can contain "</script>".
$checks['the embedded track list cannot end the script block'] =
    str_contains($play, 'JSON_HEX_TAG');
$checks['the menu is built from the tracks on the page'] =
    str_contains($playerUi, 'window.CLOUDHUB_SUBTITLES')
    && str_contains($playerUi, "list.appendChild(this.menuItem('Off', { track: '' }")
    && str_contains($playerUi, 'this.subtitleManager.setTracks(tracks)');
// An empty menu reads as a broken player; no button reads as "no subtitles".
$checks['a video with no tracks still hides the button'] =
    str_contains($playerUi, "['quality', 'audio', 'subtitles'].forEach")
    && str_contains($playerUi, 'if (!tracks.length) return;');
$checks['the choice is remembered as a language, not a file'] =
    str_contains($manager, "this.settings.set('subtitleLang', track ? (track.language || 'first') : 'off')")
    && str_contains($manager, "preference === 'off'");
$checks['a forced track is not what a language preference selects'] =
    str_contains($manager, 'matches.find((track) => !track.forced)');
$checks['cues are found without walking the whole file'] =
    str_contains($manager, 'indexAt(time)') && str_contains($manager, 'const middle = (low + high) >> 1;');
$checks['the same cue is not redrawn several times a second'] =
    str_contains($manager, 'if (signature === this.rendered) return;');
$checks['cue markup is removed rather than shown'] =
    str_contains($parser, "raw.replace(/<[^>]*>/g, '')") && str_contains($parser, 'decodeEntities');
$checks['C turns subtitles on and off'] =
    str_contains($keys, "case 'c':") && str_contains($keys, 'this.playerUI.toggleSubtitles();');
$checks['subtitles move down when the controls go away'] =
    str_contains($css, '.cfh-player-container.cfh-autohide .cfh-subtitle-display');

$bad = false;
foreach ($checks as $name => $ok) {
    echo ($ok ? '[PASS] ' : '[FAIL] ').$name.PHP_EOL;
    $bad = $bad || !$ok;
}

rmrfSubs($base);
exit($bad ? 1 : 0);
