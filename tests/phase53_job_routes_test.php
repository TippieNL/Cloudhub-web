<?php
declare(strict_types=1);

/**
 * How the background queue is wired into the front controller and the page.
 *
 * phase52 runs the queue itself. These are the contracts around it that only
 * the source shows: which guard the task routes pass through, that every one
 * of them is scoped to the signed-in account, that existing routes stay
 * synchronous unless a client offers otherwise, and that the Tasks screen's
 * script and markup agree.
 */
$root = dirname(__DIR__);
$index = (string)file_get_contents($root.'/public/index.php');
$app = (string)file_get_contents($root.'/public/assets/js/app.js');
$markup = (string)file_get_contents($root.'/views/pages/app.php');
$fileService = (string)file_get_contents($root.'/src/Services/FileService.php');
$worker = (string)file_get_contents($root.'/tools/worker.php');
$checks = [];

// --- the guard --------------------------------------------------------------------
$guard = substr($index, (int)strpos($index, '$isProtectedApi && $method'), 3000);
$checks['task routes still need a session'] = str_contains($guard, 'Authorization::requireRead();');
$checks['and a CSRF token for anything but a read'] =
    strpos($guard, 'Auth::verifyCsrf();') !== false && strpos($guard, 'Auth::verifyCsrf();') < strpos($guard, '!$isJobRoute');
$checks['only the task routes skip the blanket write check, and only that'] =
    str_contains($guard, "\$isJobRoute = \$path === '/api/jobs' || str_starts_with(\$path, '/api/jobs/');")
    && str_contains($guard, 'elseif (!in_array($path, $writeExemptPost, true) && !$isJobRoute)Authorization::requireWrite();');
$checks['server routes still need an administrator'] = str_contains($guard, "str_starts_with(\$path, '/api/servers'))Authorization::requireAdmin()");
$checks['queueing a task retires no cached listing; finishing one does'] =
    str_contains($index, "if (str_starts_with(\$path, '/api/jobs')) \$cacheNeutralWrites[] = \$path;")
    && str_contains((string)file_get_contents($root.'/src/Services/Jobs/Worker.php'), 'Cache::bumpGeneration();');

// --- the task routes ----------------------------------------------------------------
$checks['a task is always looked up with its owner'] =
    str_contains($index, "return job_env()->jobs()->findFor(\$id, (int)Auth::user()['id']) ?? throw new RuntimeException('Task not found', 404);");
$checks['a task id route only matches 32 hex characters'] = str_contains($index, "preg_match('#^/api/jobs/([a-f0-9]{32})(?:/(cancel|retry|download))?\$#'");
$create = substr($index, (int)strpos($index, "if (\$path === '/api/jobs' && \$method === 'POST')"), 1400);
$checks['only registered types a client may queue can be queued'] =
    str_contains($create, "if (\$type === null || !\$type->clientCreatable()) throw new RuntimeException('Unknown operation', 400);");
$checks["queueing checks the role the task's type needs"] =
    substr_count($create, "=== 'write') Authorization::requireWrite();") === 2;
$checks['and the per-account limit'] = str_contains($create, "queue_admit((int)\$user['id'], \$config);");
$checks['and read-only mode for a task that writes'] = str_contains($create, 'if ($type->writesStore($prepared[\'payload\'])) $fs->writable();');
$retry = substr($index, (int)strpos($index, "if (\$action === 'retry' && \$method === 'POST')"), 900);
$checks['a retry asks the role and read-only mode again'] = str_contains($retry, 'Authorization::requireWrite();') && str_contains($retry, '$fs->writable();');
$run = substr($index, (int)strpos($index, "if (\$path === '/api/jobs/run' && \$method === 'POST')"), 1500);
$checks['the web runner lets go of the session first'] = strpos($run, 'release_session_lock();') < strpos($run, 'runUntilIdle');
$checks['and answers before it works'] = strpos($run, 'finish_response_early(') < strpos($run, 'runUntilIdle');
$checks['and never sends a second answer'] = str_contains($run, 'catch (Throwable $e)') && str_contains($run, 'exit;');

// --- existing routes stay as they were ------------------------------------------------
$wanted = substr($index, (int)strpos($index, 'function queue_wanted('), 500);
$checks['a route only queues when the client offers it'] = str_contains($wanted, "if (\$asked === true) return true;")
    && str_contains($wanted, "if (\$asked !== 'auto' || queue_runner(\$config) === 'none') return false;");
$checks['copy, delete and purge are the routes that offer it'] = substr_count($index, 'queue_wanted($b, $config,') === 3;
$checks['moving items out of sight never replaces what took their place'] =
    str_contains($index, "if (file_exists(\$item) || is_link(\$item) || !@rename(\$hold.'/'.basename(\$item), \$item)) \$stranded = true;");
$checks['a background scan is not advanced by the scan route meanwhile'] = substr_count($index, 'duplicates_idle();') === 2;
$checks['.jobs is reserved at the root'] = str_contains($fileService, "public const RESERVED_ROOT_NAMES=['.trash','.thumbnails','.uploads','.jobs'];");
$checks['the worker only runs from the command line'] = str_contains($worker, "if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }");

// --- the page -------------------------------------------------------------------------
$checks['the Tasks page is served'] = (bool)preg_match("#in_array\(\\\$path, \['/', [^\]]*'/tasks'[^\]]*\], true\)#", $index);
$checks['the nav offers it'] = str_contains($markup, 'data-route="/tasks"');
$checks['the running indicator sits with the account controls'] =
    strpos($markup, 'id="tasks-indicator"') > strpos($markup, '<div class="header-actions">');
$tasksJs = substr($app, (int)strpos($app, '/* ---- Background tasks'), (int)strpos($app, 'async function route()') - (int)strpos($app, '/* ---- Background tasks'));
preg_match_all("/\\\$\('#([a-z-]+)'\)/", $tasksJs, $ids);
$missing = array_values(array_filter(array_unique($ids[1]), static fn(string $id): bool => !str_contains($markup, 'id="'.$id.'"')));
$checks['every element the task script uses exists'] = $missing === [] && count(array_unique($ids[1])) >= 6;
if ($missing) echo '       missing from app.php: '.implode(', ', $missing).PHP_EOL;
$checks['every value the task script shows is escaped'] = !preg_match('/\$\{j\.(label|target|error|currentItem|id|status)\}/', $tasksJs);
$checks['watching stops when nothing is left to watch'] =
    str_contains($tasksJs, "if (d.active || tasks.again) tasks.timer = setTimeout(pollTasks, tasks.again ? 0 : d.runner === 'none' ? 5000 : 1500);");
$checks['copies are offered to the server as tasks, moves are not'] =
    str_contains($app, "const body = verb === 'copy' ? { paths, destination, background: 'auto' } : { paths, destination };");

$bad = false;
foreach ($checks as $name => $ok) {
    echo ($ok ? '[PASS] ' : '[FAIL] ').$name.PHP_EOL;
    $bad = $bad || !$ok;
}
exit($bad ? 1 : 0);
