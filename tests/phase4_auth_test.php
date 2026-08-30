<?php
declare(strict_types=1);
$root=dirname(__DIR__);
$a=file_get_contents($root.'/src/Services/Auth.php');
$l=file_get_contents($root.'/src/Services/LoginRateLimiter.php');
$s=file_get_contents($root.'/database/schema.sql');
$i=file_get_contents($root.'/public/index.php');
$checks=[
 'Argon2id preferred'=>str_contains($a,'PASSWORD_ARGON2ID'),
 'password migration'=>str_contains($a,'password_needs_rehash'),
 // Both login and periodic rotation must regenerate the ID. Rotation passes
 // false so in-flight requests on the predecessor are not stranded, so assert
 // that regeneration happens rather than pinning the delete flag.
 'periodic session rotation'=>str_contains($a,'session_rotate_seconds')&&substr_count($a,'session_regenerate_id(')>=2,
 'login regenerates the session id'=>str_contains($a,'session_regenerate_id(true)'),
 'rotation keeps the predecessor briefly'=>str_contains($a,'session_regenerate_id(false)')&&str_contains($a,'obsolete_after'),
 'per-user throttle'=>str_contains($l,"'user'"),
 'per-IP throttle'=>str_contains($l,"'ip'"),
 'HMAC throttle keys'=>str_contains($l,'hash_hmac'),
 'generic login error'=>str_contains($i,'Invalid username or password'),
 'limiter enforced'=>str_contains($i,'assertAllowed'),
 'no bootstrap admin hash'=>!str_contains($s,"SELECT 'admin'"),
 'login attempts schema'=>str_contains($s,'CREATE TABLE IF NOT EXISTS login_attempts'),
];
$bad=false;foreach($checks as $n=>$ok){echo($ok?'[PASS] ':'[FAIL] ').$n.PHP_EOL;$bad=$bad||!$ok;}exit($bad?1:0);
