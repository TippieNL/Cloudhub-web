<?php
namespace CloudHub\Repositories;
use PDO;
final class ServerRepository {
 private PDO $db;
 public function __construct(){ $c=require dirname(__DIR__,2).'/config/database.php'; $this->db=new PDO($c['dsn'],$c['user'],$c['pass'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]); }
 public function all(bool $activeOnly=false): array { $sql='SELECT * FROM storage_servers'.($activeOnly?' WHERE is_active=1':'').' ORDER BY created_at'; $rows=$this->db->query($sql)->fetchAll(); return array_map([$this,'decode'],$rows); }
 public function get(int $id): ?array { $s=$this->db->prepare('SELECT * FROM storage_servers WHERE id=?');$s->execute([$id]);$r=$s->fetch();return $r?$this->decode($r):null; }
 public function create(array $d): array { $this->db->beginTransaction(); try { if(!empty($d['isDefault']))$this->db->exec('UPDATE storage_servers SET is_default=0'); $s=$this->db->prepare('INSERT INTO storage_servers(name,type,is_active,is_default,config) VALUES(?,?,?,?,?)');$s->execute([$d['name'],$d['type'],$d['isActive']?1:0,$d['isDefault']?1:0,json_encode($d['config'])]);$id=(int)$this->db->lastInsertId();$this->db->commit();return $this->get($id); } catch(\Throwable $e){$this->db->rollBack();throw $e;} }
 public function update(int $id,array $d): ?array { $old=$this->get($id); if(!$old)return null; $m=array_merge($old,$d); $s=$this->db->prepare('UPDATE storage_servers SET name=?,type=?,is_active=?,config=?,updated_at=CURRENT_TIMESTAMP WHERE id=?');$s->execute([$m['name'],$m['type'],$m['isActive']?1:0,json_encode($m['config']),$id]); if(!empty($d['isDefault']))$this->setDefault($id); return $this->get($id); }
 public function delete(int $id): void { $s=$this->db->prepare('DELETE FROM storage_servers WHERE id=?');$s->execute([$id]); }
 public function setDefault(int $id): void { $this->db->beginTransaction();$this->db->exec('UPDATE storage_servers SET is_default=0');$s=$this->db->prepare('UPDATE storage_servers SET is_default=1 WHERE id=?');$s->execute([$id]);$this->db->commit(); }
 private function decode(array $r): array { return ['id'=>(int)$r['id'],'name'=>$r['name'],'type'=>$r['type'],'isActive'=>(bool)$r['is_active'],'isDefault'=>(bool)$r['is_default'],'config'=>json_decode($r['config'],true)?:[],'createdAt'=>$r['created_at'],'updatedAt'=>$r['updated_at']]; }
}
