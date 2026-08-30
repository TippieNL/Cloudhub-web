<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/Helpers/Http.php';
use CloudHub\Helpers\Http;
$bad=false;

$_SERVER['SCRIPT_NAME']='/Cloud-File-Hub-PHP/index.php';
$_SERVER['REQUEST_URI']='/Cloud-File-Hub-PHP/?route=%2Fapi%2Fauth%2Fstatus';
$_GET=['route'=>'/api/auth/status'];
$base=Http::basePath();
$path=Http::requestPath($base);

$cfg=file_get_contents(dirname(__DIR__).'/config/config.php');
$auth=file_get_contents(dirname(__DIR__).'/src/Services/Auth.php');
$env=file_get_contents(dirname(__DIR__).'/.env.example');

$checks=[
 'query front-controller auth route'=>$path==='/api/auth/status',
 'env defaults to user file store'=>str_contains($env,'ROOT_DIR=storage/files'),
 // Single quotes: in double quotes PHP interpolated the undefined
 // $normalisedRoot, so this asserted on " === 'storage'" and passed by luck.
 'legacy storage compatibility'=>str_contains($cfg,'$normalisedRoot === \'storage\''),
 'no Android shared-storage session path'=>!str_contains($auth,"storage/.sessions"),
 'no forced session save path'=>!str_contains($auth,"ini_set('session.save_path'"),
];
foreach($checks as $n=>$ok){echo($ok?'[PASS] ':'[FAIL] ').$n.PHP_EOL;$bad=$bad||!$ok;}
exit($bad?1:0);
