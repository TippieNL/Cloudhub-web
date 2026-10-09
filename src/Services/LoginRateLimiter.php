<?php
declare(strict_types=1);
namespace CloudHub\Services;

use PDO;

/**
 * Database-backed authentication throttle.
 * Keys are HMACed before storage so raw usernames/IP addresses are not retained.
 */
final class LoginRateLimiter {
 /** @var \Closure(): int */
 private \Closure $clock;

 public function __construct(private PDO $pdo, private array $config, ?\Closure $clock = null) {
  $this->clock = $clock ?? static fn(): int => time();
 }

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

 /**
  * Take one of the $max slots $scope allows $value in the last $window seconds.
  *
  * Used by two-step verification for every code checked and every text sent.
  * The slot is inserted first and counted after, so requests arriving together
  * cannot all see room and all go ahead: each one counts the others too, and
  * at worst one is refused that need not have been -- never one let through
  * that should not. A refused request hands its slot straight back, so asking
  * again while refused does not lengthen the wait.
  *
  * @return int|null the slot, to release() if what it was taken for turns out
  *                  not to count; null when none is left
  */
 public function claim(string $scope,string $value,int $max,int $window): ?int {
  $key=$this->key($scope,$value);$now=($this->clock)();
  $this->pdo->prepare('INSERT INTO login_attempts (scope, attempt_key, attempted_at) VALUES (?,?,?)')->execute([$scope,$key,gmdate('Y-m-d H:i:s',$now)]);
  $slot=(int)$this->pdo->lastInsertId();
  $taken=$this->count($scope,$key,gmdate('Y-m-d H:i:s',$now-$window));
  // Our own slot has to be among them. A database migrate.php has not yet
  // updated, running in MySQL's non-strict mode, stores an unknown ENUM value
  // as '' without complaint -- and a limit that counts nothing is no limit, so
  // that is refused rather than waved through.
  if($taken<1){$this->release($slot);throw new \RuntimeException('Two-step verification needs a database update: run php database/migrate.php on the server.',503);}
  if($taken>max(1,$max)){$this->release($slot);return null;}
  return $slot;
 }

 public function release(int $slot): void {
  $this->pdo->prepare('DELETE FROM login_attempts WHERE id=?')->execute([$slot]);
 }

 /** Seconds until claim() would find a free slot, 0 when it would now. */
 public function retryAfter(string $scope,string $value,int $max,int $window): int {
  $now=($this->clock)();$max=max(1,$max);
  // A slot frees when the $max-th newest attempt in the window ages out.
  $stmt=$this->pdo->prepare('SELECT attempted_at FROM login_attempts WHERE scope=? AND attempt_key=? AND attempted_at>=? ORDER BY attempted_at DESC LIMIT 1 OFFSET '.($max-1));
  $stmt->execute([$scope,$this->key($scope,$value),gmdate('Y-m-d H:i:s',$now-$window)]);
  $at=$stmt->fetchColumn();
  if(!is_string($at))return 0;
  $since=strtotime($at.' UTC');
  return $since===false?$window:max(1,$since+$window-$now);
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
