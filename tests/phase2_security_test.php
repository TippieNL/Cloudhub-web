<?php
$root=dirname(__DIR__);
$a=file_get_contents($root.'/src/Services/Auth.php');
$s=file_get_contents($root.'/src/Services/Security.php');
$i=file_get_contents($root.'/public/index.php');
$checks=[
'strict session mode'=>str_contains($a,'session.use_strict_mode'),
'cookie-only sessions'=>str_contains($a,'session.use_only_cookies'),
'session regeneration'=>str_contains($a,'session_regenerate_id(true)'),
'password rehash'=>str_contains($a,'password_needs_rehash'),
'CSRF constant-time comparison'=>str_contains($s,'hash_equals'),
'cross-site rejection'=>str_contains($s,'cross-site'),
'CSP'=>str_contains($s,'Content-Security-Policy'),
'HSTS gating'=>str_contains($s,'hsts_enabled'),
'central policy active'=>str_contains($i,'Security::applyHeaders'),
];
$failed=false;foreach($checks as $n=>$ok){echo ($ok?'[PASS] ':'[FAIL] ').$n.PHP_EOL;$failed=$failed||!$ok;}exit($failed?1:0);
