<?php
declare(strict_types=1);
namespace CloudHub\Services;

use PDO;

/**
 * Database-backed authentication throttle.
 * Keys are HMACed before storage so raw usernames/IP addresses are not retained.
 */
final class LoginRateLimiter {
 public function __construct(private PDO $pdo, private array $config) {}

 public function clientIp(): string {
  // REMOTE_ADDR is authoritative unless a trusted proxy is explicitly enabled.
  if(($this->config['trust_proxy']??false)===true){
   $forwarded=trim(explode(',',(string)($_SERVER['HTTP_X_FORWARDED_FOR']??''))[0]);
   if(filter_var($forwarded,FILTER_VALIDATE_IP))return $forwarded;
  }
  $remote=(string)($_SERVER['REMOTE_ADDR']??'unknown');
  return filter_var($remote,FILTER_VALIDATE_IP)?$remote:'unknown';
 }

 public function assertAllowed(string $username): void {
  $this->cleanupOccasionally();
  $window=max(60,(int)($this->config['login_rate_window_seconds']??900));
  $userMax=max(1,(int)($this->config['login_rate_user_attempts']??5));
  $ipMax=max($userMax,(int)($this->config['login_rate_ip_attempts']??20));
  $since=gmdate('Y-m-d H:i:s',time()-$window);

  if($this->count('user',$this->key('user',$this->normaliseUser($username)),$since)>=$userMax ||
     $this->count('ip',$this->key('ip',$this->clientIp()),$since)>=$ipMax){
   throw new \RuntimeException('Too many login attempts. Try again later.',429);
  }
 }

 public function recordFailure(string $username): void {
  $now=gmdate('Y-m-d H:i:s');
  $stmt=$this->pdo->prepare('INSERT INTO login_attempts (scope, attempt_key, attempted_at) VALUES (?,?,?)');
  $stmt->execute(['user',$this->key('user',$this->normaliseUser($username)),$now]);
  $stmt->execute(['ip',$this->key('ip',$this->clientIp()),$now]);
 }

 public function clearUserFailures(string $username): void {
  $stmt=$this->pdo->prepare("DELETE FROM login_attempts WHERE scope='user' AND attempt_key=?");
  $stmt->execute([$this->key('user',$this->normaliseUser($username))]);
 }

 private function count(string $scope,string $key,string $since): int {
  $stmt=$this->pdo->prepare('SELECT COUNT(*) FROM login_attempts WHERE scope=? AND attempt_key=? AND attempted_at>=?');
  $stmt->execute([$scope,$key,$since]);return (int)$stmt->fetchColumn();
 }
 private function normaliseUser(string $u): string{return strtolower(trim($u));}
 private function key(string $scope,string $value): string {
  $secret=(string)($this->config['rate_limit_secret']??'');
  if($secret==='')$secret=hash('sha256',(string)($this->config['app_url']??'cloudhub').'|cloudhub-rate-limit');
  return hash_hmac('sha256',$scope."\0".$value,$secret);
 }
 private function cleanupOccasionally(): void {
  if(random_int(1,100)!==1)return;
  $ttl=max(3600,(int)($this->config['login_rate_retention_seconds']??86400));
  $stmt=$this->pdo->prepare('DELETE FROM login_attempts WHERE attempted_at < ?');
  $stmt->execute([gmdate('Y-m-d H:i:s',time()-$ttl)]);
 }
}
