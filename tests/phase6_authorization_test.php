<?php
$r=dirname(__DIR__);
$a=file_get_contents($r.'/src/Services/Authorization.php');
$x=file_get_contents($r.'/src/Services/Auth.php');
$i=file_get_contents($r.'/public/index.php');
$u=file_get_contents($r.'/src/Services/UploadService.php');
$s=file_get_contents($r.'/database/schema.sql');
$c=[
 'roles schema'=>str_contains($s,"ENUM('viewer','editor','admin')"),
 'login role'=>str_contains($x,'role FROM users'),
 'session role'=>str_contains($x,"SESSION['role']"),
 'write capability'=>str_contains($a,'requireWrite'),
 'admin capability'=>str_contains($a,'requireAdmin'),
 'router write'=>str_contains($i,'Authorization::requireWrite()'),
 'admin route'=>str_contains($i,'Authorization::requireAdmin()'),
 'upload owner'=>str_contains($u,'ownerUserId'),
 'owner check'=>str_contains($u,'assertOwner')
];
$bad=false;
foreach($c as $n=>$ok){echo($ok?'[PASS] ':'[FAIL] ').$n.PHP_EOL;$bad=$bad||!$ok;}
exit($bad?1:0);
