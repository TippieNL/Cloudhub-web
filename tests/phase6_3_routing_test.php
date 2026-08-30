<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/Helpers/Http.php';
use CloudHub\Helpers\Http;
$bad=false;
$_SERVER['SCRIPT_NAME']='/Cloud-File-Hub-PHP/index.php';
$_SERVER['REQUEST_URI']='/Cloud-File-Hub-PHP/api/auth/status';
$base=Http::basePath();$path=Http::requestPath($base);
$index=file_get_contents(dirname(__DIR__).'/public/index.php');
// Locate the logout route tolerantly: matching the exact spelling
// "if(\$path==='/api/auth/logout'" meant a reformat of public/index.php --
// which now reads "if (\$path === ..." -- silently emptied $logout, failing
// this check and passing the requireWrite one below for the wrong reason.
$logoutStart=preg_match('/^if\s*\(\s*\$path\s*===\s*\'\/api\/auth\/logout\'/m',$index,$m,PREG_OFFSET_CAPTURE)?$m[0][1]:false;
$logoutEnd=$logoutStart===false?false:(preg_match('/^if\s*\(/m',$index,$m2,PREG_OFFSET_CAPTURE,$logoutStart+1)?$m2[0][1]:false);
$logout=$logoutStart===false?'':substr($index,$logoutStart,($logoutEnd?:strlen($index))-$logoutStart);
$checks=[
 'android subdirectory base path'=>$base==='/Cloud-File-Hub-PHP',
 'android API route stripping'=>$path==='/api/auth/status',
 'router passes basePath to requestPath'=>str_contains($index,'Http::requestPath($basePath)'),
 'logout route exists'=>$logoutStart!==false,
 // Guard against the vacuous pass: an empty $logout satisfies the negative
 // check below without the route having been found at all.
 'logout block was actually captured'=>$logout!=='',
 'logout does not require write role'=>$logout!==''&&!str_contains($logout,'requireWrite'),
 'logout verifies CSRF'=>str_contains($logout,'verifyCsrf'),
 'auth endpoints excluded from protected guard'=>str_contains($index,'$isAuthEndpoint'),
 'API catch-all before page rendering'=>strpos($index,'API endpoint not found')<strrpos($index,'views/pages/app.php')
];
foreach($checks as $n=>$ok){echo($ok?'[PASS] ':'[FAIL] ').$n.PHP_EOL;$bad=$bad||!$ok;}
exit($bad?1:0);
