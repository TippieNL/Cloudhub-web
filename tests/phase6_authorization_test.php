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
 'owner check'=>str_contains($u,'assertOwner'),
 // The write guard covers every method that is not a read, by exclusion --
 // not a POST/PUT/PATCH/DELETE list, which let WebDAV's MKCOL and MOVE past
 // with only a session. PROPFIND is WebDAV's read. A revert to the old list
 // silently reopens the viewer-can-delete-any-file hole, so pin the shape.
 'write guard covers non-read verbs'=>str_contains($i,"!in_array(\$method, ['GET', 'HEAD', 'OPTIONS', 'PROPFIND'], true)")
];
$bad=false;
foreach($c as $n=>$ok){echo($ok?'[PASS] ':'[FAIL] ').$n.PHP_EOL;$bad=$bad||!$ok;}
exit($bad?1:0);
