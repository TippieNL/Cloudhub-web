<?php
$r=dirname(__DIR__);
$i=file_get_contents($r.'/public/index.php');
$a=file_get_contents($r.'/src/Services/AuditLog.php');
$s=file_get_contents($r.'/database/schema.sql');
$h=file_get_contents($r.'/.htaccess');
$c=[
 'audit schema'=>str_contains($s,'CREATE TABLE IF NOT EXISTS security_events'),
 'audit service'=>str_contains($a,'final class AuditLog'),
 'sensitive context redaction'=>str_contains($a,"'password'")&&str_contains($a,"'token'"),
 'login audit'=>str_contains($i,"'auth.login'"),
 'logout audit'=>str_contains($i,"'auth.logout'"),
 'admin event endpoint'=>str_contains($i,"'/api/security/events'"),
 // Single quotes: in double quotes PHP interpolated the undefined $mime, so
 // this asserted on "==='text/html'" and passed by luck. Inline preview is an
 // allowlist, so assert text/html is absent from it whatever the spacing.
 // Scoped to the preview gate rather than the whole file. A file-wide search
 // for "=== 'text/html'" can no longer tell an allowlist entry from a denial:
 // mime_renders_markup() names text/html precisely in order to refuse it.
 'HTML not inline preview'=>!preg_match('/\$inline = \(.*\$mime\s*===\s*\'text\/html\'.*\);/',$i),
 'inline preview is an allowlist'=>str_contains($i,'$inline = (str_starts_with($mime,')&&str_contains($i,"\$mime === 'text/plain'"),
 'inline preview also denies markup'=>str_contains($i,'&& !mime_renders_markup($mime);'),
 'share sandbox CSP'=>str_contains($i,"sandbox; img-src"),
 'share nosniff'=>str_contains($i,"X-Content-Type-Options: nosniff"),
 'backup deny rule'=>str_contains($h,'bak|old|orig|save|sql|log|ini|dist'),
];
$bad=false;foreach($c as $n=>$ok){echo($ok?'[PASS] ':'[FAIL] ').$n.PHP_EOL;$bad=$bad||!$ok;}exit($bad?1:0);
