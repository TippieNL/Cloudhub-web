<?php
declare(strict_types=1);

/**
 * Run CloudHub's background tasks: copies, archives, extraction, deletion of
 * large folders, thumbnails, duplicate scans and checksums.
 *
 *   php tools/worker.php                keep running, waiting for work
 *   php tools/worker.php --once         run what is queued now, then exit
 *   php tools/worker.php --max-jobs=50  exit after 50 jobs (a supervisor restarts it)
 *   php tools/worker.php --max-seconds=3600
 *   php tools/worker.php --sleep=2      seconds between looks at an empty queue
 *   php tools/worker.php --status       print the queue's counts and exit
 *
 * Run it as the user the web server runs as, so the files it writes belong to
 * the same account the web server reads them as. Several may run at once, on
 * one machine or several sharing the database. SIGTERM or Ctrl+C lets the job
 * in hand reach its next checkpoint and go back to the queue; a worker killed
 * outright has its job taken back by the next worker that looks.
 *
 * With no worker running, the web app runs queued tasks itself after
 * answering a request (QUEUE_RUNNER=auto), which keeps an install with no PHP
 * command line -- KSWEB may be one -- working. A worker is still the better
 * home for long tasks: it is not subject to the web server's time limits.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

require dirname(__DIR__).'/config/bootstrap.php';

use CloudHub\Helpers\Cache;
use CloudHub\Helpers\Db;
use CloudHub\Services\FileService;
use CloudHub\Services\Jobs\JobEnvironment;
use CloudHub\Services\Jobs\JobTypes;
use CloudHub\Services\Jobs\Worker;
use CloudHub\Services\Jobs\WorkerRegistry;

/** @var array $config from config/bootstrap.php */
$options = getopt('', ['once', 'max-jobs:', 'max-seconds:', 'sleep:', 'status', 'quiet']);
$once = isset($options['once']);
$maxJobs = isset($options['max-jobs']) ? max(1, (int)$options['max-jobs']) : null;
$maxSeconds = isset($options['max-seconds']) ? max(1, (int)$options['max-seconds']) : null;
$sleep = isset($options['sleep']) ? max(0.2, (float)$options['sleep']) : 2.0;
$quiet = isset($options['quiet']);

$log = static function (string $line) use ($quiet): void {
    if (!$quiet) fwrite(STDOUT, gmdate('Y-m-d\TH:i:s\Z').' '.$line.PHP_EOL);
};

// A worker runs for hours: the request limit is for requests.
@set_time_limit(0);
Cache::configure($config);
$projectDir = dirname(__DIR__);
$registry = new WorkerRegistry($projectDir.'/storage/.cache/queue');
$build = static function (?string $id = null) use ($config, $projectDir, $registry, $log): Worker {
    Db::reset();
    $env = new JobEnvironment($config, new FileService($config), Db::connection(), $projectDir);
    return new Worker($env, JobTypes::standard(), $registry, 'cli', $log, $id);
};

if (isset($options['status'])) {
    $env = new JobEnvironment($config, new FileService($config), Db::connection(), $projectDir);
    foreach ($env->jobs()->counts() as $status => $n) echo str_pad($status, 11).$n.PHP_EOL;
    $alive = $registry->alive();
    echo 'workers    '.count($alive).($alive ? ' ('.implode(', ', $alive).')' : '').PHP_EOL;
    exit(0);
}

$worker = $build();
if (function_exists('pcntl_async_signals')) {
    pcntl_async_signals(true);
    foreach ([SIGTERM, SIGINT, SIGHUP] as $signal) {
        pcntl_signal($signal, static function () use (&$worker, $log): void {
            $log('stopping: the job in hand goes back to the queue at its next checkpoint');
            $worker->requestStop();
        });
    }
}

$log('worker '.$worker->id().' started'.($once ? ' (--once)' : ''));
$started = microtime(true);
$ran = 0;
$failures = 0;
while (!$worker->stopping()) {
    if ($maxJobs !== null && $ran >= $maxJobs) break;
    if ($maxSeconds !== null && microtime(true) - $started >= $maxSeconds) break;
    try {
        $job = $worker->runOnce();
        $failures = 0;
    } catch (Throwable $e) {
        // Most likely the database: gone away after a long idle, or
        // restarting. Log it, wait, and start again on a fresh connection.
        $failures++;
        $log('error: '.get_class($e).': '.$e->getMessage());
        error_log('[worker] '.$e);
        if ($once && $failures >= 3) exit(1);
        usleep((int)(min(60, 2 ** min($failures, 6)) * 1e6));
        if (!$worker->stopping()) $worker = $build($worker->id());
        continue;
    }
    if ($job !== null) { $ran++; continue; }
    if ($once) break;
    // Idle: look again shortly, staying responsive to a stop.
    for ($waited = 0.0; $waited < $sleep && !$worker->stopping(); $waited += 0.2) {
        usleep(200000);
        $worker->beat();
    }
}
$worker->shutdown();
$log('worker '.$worker->id().' stopped after '.$ran.' job'.($ran === 1 ? '' : 's'));
exit(0);
