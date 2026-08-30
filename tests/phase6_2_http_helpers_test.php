<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/Helpers/Http.php';
use CloudHub\Helpers\Http;

$bad=false;
$cases=[
 ['/Cloud-File-Hub-PHP/api/files?x=1','/Cloud-File-Hub-PHP','/api/files'],
 ['/Cloud-File-Hub-PHP/','/Cloud-File-Hub-PHP','/'],
 ['/api/files','','/api/files'],
];
foreach($cases as [$uri,$base,$expected]){
 $_SERVER['REQUEST_URI']=$uri;
 $actual=Http::requestPath($base);
 $ok=$actual===$expected;
 echo($ok?'[PASS] ':'[FAIL] ').$uri.' => '.$actual.PHP_EOL;
 $bad=$bad||!$ok;
}
exit($bad?1:0);
