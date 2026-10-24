<?php
declare(strict_types=1);

/**
 * Make the thumbnails for everything already in the store, from the command line.
 *
 *   php tools/thumbnails.php            carry on, or begin if the last scan is a day old
 *   php tools/thumbnails.php --restart  begin a new scan now
 *   php tools/thumbnails.php --status   say how far the last scan got, doing nothing
 *
 * The same scan the Android app runs in the background through POST
 * /api/thumbnails/scan, and the same lock: one at a time, whoever started it.
 * Here the slices are longer and run back to back, so a large store is done in
 * one sitting -- suitable for cron, too, since a scan that finished within the
 * day is not begun again.
 *
 * Run it as the user the web server runs as, as tools/worker.php is: the
 * thumbnails it writes are ones the web server later replaces and removes.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

require dirname(__DIR__).'/config/bootstrap.php';

use CloudHub\Services\FileService;
use CloudHub\Services\ThumbnailScan;
use CloudHub\Services\ThumbnailService;

$args = array_slice($argv, 1);
$restart = in_array('--restart', $args, true);
$statusOnly = in_array('--status', $args, true);
foreach ($args as $arg) {
    if (!in_array($arg, ['--restart', '--status'], true)) {
        fwrite(STDERR, "Unknown option: $arg\nUsage: php tools/thumbnails.php [--restart|--status]\n");
        exit(2);
    }
}

/** @var array $config from config/bootstrap.php */
$fs = new FileService($config);
$thumbs = new ThumbnailService($fs, dirname(__DIR__).'/storage/.thumbnails');
$scan = new ThumbnailScan($fs, $thumbs, dirname(__DIR__).'/storage/.cache');

$line = function (array $p): string {
    $phase = $p['done'] ? 'done' : ($p['phase'] ?? 'not started');
    return sprintf('%-6s %d found, %d checked: %d made, %d already there, %d frames kept, %d unreadable, %d skipped, %d removed',
        $phase, $p['found'], $p['processed'], $p['generated'], $p['valid'], $p['adopted'], $p['failed'], $p['skipped'], $p['removed']);
};

if ($statusOnly) {
    $p = $scan->progress();
    fwrite(STDOUT, ($p['started'] ? $line($p) : 'No thumbnail scan has run yet.').PHP_EOL);
    exit(0);
}

if (!extension_loaded('gd')) {
    fwrite(STDERR, "The PHP GD extension is not loaded, so no image thumbnails can be made.\n");
    exit(1);
}

try {
    do {
        $p = $scan->run(30, $restart);
        $restart = false;
        if ($p['busy']) {
            fwrite(STDOUT, 'busy   another scan is running -- '.$line($p).PHP_EOL);
            sleep(5);
            continue;
        }
        fwrite(STDOUT, $line($p).PHP_EOL);
    } while (!$p['done']);
} catch (Throwable $e) {
    fwrite(STDERR, 'The thumbnail scan stopped: '.$e->getMessage().PHP_EOL);
    exit(1);
}
exit(0);
