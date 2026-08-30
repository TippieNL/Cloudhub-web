<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require dirname(__DIR__).'/config/bootstrap.php';
$c=require dirname(__DIR__).'/config/database.php';
$pdo=new PDO($c['dsn'],$c['user'],$c['pass'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$days=max(1,(int)($config['security_event_retention_days']??90));
$stmt=$pdo->prepare('DELETE FROM security_events WHERE created_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL ? DAY)');
$stmt->execute([$days]);
echo "Removed ".$stmt->rowCount()." security event(s).\n";
