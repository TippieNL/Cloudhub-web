<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/Helpers/Http.php';
use CloudHub\Helpers\Http;

$cases=[
 ['/Cloud-File-Hub-PHP/index.php','/Cloud-File-Hub-PHP'],
 ['/Cloud-File-Hub-PHP/public/index.php','/Cloud-File-Hub-PHP'],
 ['/index.php',''],
 ['/public/index.php',''],
];

$bad=false;
foreach($cases as [$script,$expected]){
 $_SERVER['SCRIPT_NAME']=$script;
 $actual=Http::basePath();
 $ok=$actual===$expected;
 echo($ok?'[PASS] ':'[FAIL] ').$script.' => '.var_export($actual,true).PHP_EOL;
 $bad=$bad||!$ok;
}
exit($bad?1:0);
