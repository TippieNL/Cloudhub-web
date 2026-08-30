<?php
$r=dirname(__DIR__);
$a=file_get_contents($r.'/src/Services/Auth.php');
$m=file_get_contents($r.'/database/migrations/20260725_phase6_5_role_repair.sql');
$c=[
 'no forced shared-storage sessions'=>!str_contains($a,"storage/.sessions"),
 'role fallback exists'=>str_contains($a,"'viewer' AS role"),
 'role migration checks schema'=>str_contains($m,'information_schema.COLUMNS'),
 'admin promoted'=>str_contains($m,"role='admin'"),
];
$bad=false;foreach($c as $n=>$ok){echo($ok?'[PASS] ':'[FAIL] ').$n.PHP_EOL;$bad=$bad||!$ok;}exit($bad?1:0);
