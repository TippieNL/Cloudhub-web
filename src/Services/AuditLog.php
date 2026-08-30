<?php
declare(strict_types=1);
namespace CloudHub\Services;

use PDO;

/**
 * Best-effort security audit trail.
 *
 * Audit failures never break the primary operation. Context is deliberately
 * compact and must never contain passwords, CSRF tokens, share tokens or
 * storage credentials.
 */
final class AuditLog {
 public static function write(PDO $pdo,string $event,string $outcome='success',array $context=[]): void {
  try {
   $uid=isset($_SESSION['user_id'])?(int)$_SESSION['user_id']:null;
   $username=isset($_SESSION['username'])?(string)$_SESSION['username']:null;
   $ip=(string)($_SERVER['REMOTE_ADDR']??'');
   $ua=substr((string)($_SERVER['HTTP_USER_AGENT']??''),0,255);
   $requestId=\CloudHub\Helpers\Http::requestId();
   $safe=self::sanitize($context);
   $stmt=$pdo->prepare('INSERT INTO security_events(user_id,username,event_type,outcome,ip_address,user_agent,request_id,context_json,created_at) VALUES(?,?,?,?,?,?,?,?,UTC_TIMESTAMP())');
   $stmt->execute([$uid,$username,substr($event,0,80),substr($outcome,0,20),substr($ip,0,45),$ua,$requestId,$safe?json_encode($safe,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE):null]);
  } catch (\Throwable $e) {
   error_log('[audit] '.$e->getMessage());
  }
 }
 private static function sanitize(array $context): array {
  $blocked=['password','password_hash','csrf','token','apiKey','privateKey','secret','authorization'];
  $out=[];
  foreach($context as $k=>$v){
   if(in_array((string)$k,$blocked,true))continue;
   if(is_scalar($v)||$v===null)$out[(string)$k]=is_string($v)?substr($v,0,500):$v;
  }
  return $out;
 }
}
