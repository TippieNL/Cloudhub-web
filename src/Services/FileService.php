<?php
declare(strict_types=1);
namespace CloudHub\Services;
use RuntimeException;

/**
 * Filesystem boundary for CloudHub-managed storage.
 *
 * Client paths are virtual paths rooted at ROOT_DIR. Traversal, control
 * characters, absolute filesystem paths and symlink traversal are rejected.
 */
final class FileService {
 /**
  * Directory names at the storage root that hold CloudHub's own state.
  *
  * They are never listed and can never be addressed by a client path.
  * Without this the trash could be browsed and hard-deleted through the
  * ordinary file routes, which defeats the point of having a trash.
  *
  * .uploads holds in-progress upload staging. It lives here for the same
  * reason .trash does: finishing an upload is then a same-filesystem rename
  * rather than a copy of every byte. See UploadService::__construct().
  */
 public const RESERVED_ROOT_NAMES=['.trash','.thumbnails','.uploads'];

 /**
  * Whether a top-level name is one of CloudHub's own directories.
  *
  * Compared the way the filesystem underneath may compare it. Android's shared
  * storage, macOS and Windows resolve names case-insensitively, and Windows
  * also drops trailing dots and spaces -- so ".Trash" or ".trash." opens the
  * real trash there while an exact comparison waved it through.
  */
 public static function isReservedRootName(string $name): bool {
  return in_array(strtolower(rtrim($name,' .')),self::RESERVED_ROOT_NAMES,true);
 }

 private string $root;
 public function __construct(private array $config) {
  $real=realpath((string)$config['root_dir']);
  if($real===false||!is_dir($real))throw new RuntimeException('Storage root is unavailable',500);
  $this->root=rtrim(str_replace('\\','/',$real),'/');
 }
 public function root(): string{return $this->root;}

 public function sanitize(string $requested): string {
  if(str_contains($requested,"\0"))throw new RuntimeException('Invalid path',400);
  /*
   * Not percent-decoded here. Every caller hands over a path that is already
   * decoded -- PHP decodes $_GET, JSON bodies are never encoded, and the
   * router decodes the URL path once -- so decoding again only corrupted names
   * that legitimately contain a percent sign: "Report%202024.pdf", as wget
   * saves it, was looked up as "Report 2024.pdf" and could be listed but never
   * opened, renamed or deleted. A literal "%2e%2e" is a name, not "..": the
   * filesystem does not decode it either, so it cannot climb out of the root.
   */
  $decoded=str_replace('\\','/',$requested);
  // Virtual paths may start with one slash, but native absolute/drive/UNC paths are never accepted.
  if(preg_match('/^[A-Za-z]:\//',$decoded)||str_starts_with($decoded,'//'))throw new RuntimeException('Absolute filesystem paths are not allowed',400);
  $parts=explode('/',ltrim($decoded,'/'));$safe=[];
  foreach($parts as $part){
   if($part===''||$part==='.')continue;
   if($part==='..')throw new RuntimeException('Path traversal is not allowed',400);
   if(preg_match('/[\x00-\x1F\x7F]/u',$part))throw new RuntimeException('Control characters are not allowed in paths',400);
   $safe[]=$part;
  }
  if($safe&&self::isReservedRootName($safe[0]))throw new RuntimeException('That path is reserved',403);
  $candidate=$this->root.($safe?'/'.implode('/',$safe):'');
  $this->assertNoSymlinkTraversal($candidate);
  return $candidate;
 }

 /** Resolve an existing path and prove its canonical target remains under ROOT_DIR. */
 public function existing(string $requested): string {
  $candidate=$this->sanitize($requested);
  $real=realpath($candidate);
  if($real===false)throw new RuntimeException('File or directory not found',404);
  $real=str_replace('\\','/',$real);$this->assertContained($real);
  // sanitize() has already walked this exact path with pathContainsSymlink().
  // Repeating it here re-stat'ed every component for no possible change of
  // verdict -- roughly a third of the syscalls of every existing() call, on
  // the hottest path in the application.
  return $real;
 }

 /** Resolve a destination while requiring its existing parent to be canonical and contained. */
 public function destination(string $requested): string {
  $candidate=$this->sanitize($requested);
  if($candidate===$this->root)throw new RuntimeException('The storage root cannot be used as a destination item',400);
  $parent=dirname($candidate);$realParent=realpath($parent);
  if($realParent===false||!is_dir($realParent))throw new RuntimeException('Destination parent directory not found',404);
  $realParent=str_replace('\\','/',$realParent);$this->assertContained($realParent);
  if($this->pathContainsSymlink($parent))throw new RuntimeException('Symlink destinations are not allowed',403);
  return rtrim($realParent,'/').'/'.basename($candidate);
 }

 public function relative(string $full): string {
  $full=str_replace('\\','/',$full);$this->assertContained($full);
  $rel=substr($full,strlen($this->root));return $rel===''?'/':$rel;
 }

 public function escapingSymlink(string $path): bool {
  try{return $this->pathContainsSymlink($path);}catch(\Throwable){return true;}
 }

 public function list(string $path): array {
  $dir=$this->existing($path);if(!is_dir($dir))throw new RuntimeException('Directory not found',404);
  $out=[];foreach($this->children($dir) as $full)$out[]=$this->entry($full);
  usort($out,fn($a,$b)=>$a['isDirectory']!==$b['isDirectory']?($a['isDirectory']?-1:1):strcasecmp($a['name'],$b['name']));return $out;
 }

 /**
  * Readable children of one directory, symlinks and reserved names removed.
  *
  * The reserved skip only applies at the storage root, because that is the
  * only place CloudHub puts those directories -- a user's own folder called
  * ".trash" three levels down is their file and stays visible.
  *
  * @return list<string> absolute paths
  */
 private function children(string $dir): array {
  /*
   * The parent chain is proven once for the directory rather than re-walked
   * from the storage root for every entry in it.
   *
   * Every entry of one scandir() shares the same parent chain by
   * construction, so the old per-entry walk re-stat'ed the same components N
   * times for N files. Measured on a 637-entry directory: 5.01ms against
   * 1.44ms for identical output. On Android's FUSE-backed storage, where a
   * stat costs 5-20x what it does on a server filesystem, that repetition was
   * the single largest cost of opening a folder.
   *
   * Equivalent to the old check: if $dir's chain contains a symlink then so
   * does every path beneath it, and every entry would have been skipped --
   * which is the empty list returned here. Otherwise only the final component
   * remains to be tested, which is exactly is_link($full).
   */
  if($this->escapingSymlink($dir))return [];
  $atRoot=rtrim($dir,'/')===$this->root;$out=[];
  foreach(scandir($dir)?:[] as $name){
   if($name==='.'||$name==='..')continue;
   if($atRoot&&self::isReservedRootName($name))continue;
   $full=$dir.'/'.$name;if(is_link($full))continue;
   $out[]=$full;
  }
  return $out;
 }

 /**
  * The readable children of a directory as absolute paths.
  *
  * children() applies the symlink and reserved-name rules that every traversal
  * in this class depends on; a caller that walks the tree itself needs those
  * same rules, not its own scandir().
  *
  * @return list<string>
  */
 public function childPaths(string $dir): array { return $this->children($dir); }

 /** The listing row for one item that exists, for a caller holding a path rather than a folder. */
 public function describe(string $requested): array {return $this->entry($this->existing($requested));}

 /** One listing row. Shared so search results and folder listings never drift apart. */
 private function entry(string $full): array {
  $name=basename($full);$isDir=is_dir($full);
  return ['name'=>$name,'path'=>$this->relative($full),'isDirectory'=>$isDir,'size'=>$isDir?0:(filesize($full)?:0),'modified'=>gmdate('c',filemtime($full)?:time()),...(!$isDir&&pathinfo($name,PATHINFO_EXTENSION)!==''?['extension'=>'.'.pathinfo($name,PATHINFO_EXTENSION)]:[])];
 }

 public function writable(): void {if($this->config['read_only'])throw new RuntimeException('Server is in read-only mode',403);}

 public function safeName(string $name): string {
  if(str_contains($name,"\0"))throw new RuntimeException('Invalid filename',400);
  $n=basename(str_replace('\\','/',$name));
  if($n===''||$n==='.'||$n==='..'||preg_match('/[\x00-\x1F\x7F]/u',$n))throw new RuntimeException('Invalid filename',400);
  if(strlen($n)>255)throw new RuntimeException('Filename is too long',400);
  return $n;
 }

 public function deleteTree(string $path): void {
  $this->assertContained(str_replace('\\','/',$path));
  if($path===$this->root)throw new RuntimeException('Storage root cannot be deleted',403);
  if(is_link($path)){if(!unlink($path))throw new RuntimeException('Unable to remove symlink',500);return;}
  if(is_dir($path)){
   foreach(scandir($path)?:[] as $n)if($n!=='.'&&$n!=='..')$this->deleteTree($path.'/'.$n);
   if(!rmdir($path))throw new RuntimeException('Unable to remove directory',500);
  }elseif(file_exists($path)&&!unlink($path))throw new RuntimeException('Unable to remove file',500);
 }


 /** The most entries one search examines. Beyond it the answer says `truncated`. */
 public const SEARCH_MAX_NODES=200000;

 /**
  * Name search below $path, breadth first.
  *
  * Bounded three ways, each reported rather than hidden: $limit caps what comes
  * back, $maxNodes caps what is examined, and $deadline -- a microtime(true)
  * value -- caps how long the walk may run, after which `incomplete` says it
  * stopped for time. searchWithIndex() is the same walk, resumable.
  *
  * Breadth first so that a bound, where one is reached, costs the deepest
  * corners of the tree rather than whole top-level folders. The walk used to
  * go depth first from the last folder, under a 20,000-entry cap: on a phone
  * that cap was spent inside whichever folders came first -- on a measured
  * 44,000-file storage, WhatsApp's media under Android/ was never opened, and
  * a photo that was there was reported as not found.
  *
  * @return array{results:list<array>,truncated:bool,incomplete:bool,scanned:int}
  */
 public function search(string $path,string $needle,int $limit=200,int $maxNodes=self::SEARCH_MAX_NODES,?float $deadline=null): array {
  return $this->searchWithIndex($path,$needle,$limit,$maxNodes,null,$deadline)[0];
 }

 /** Seconds a directory's time stamps must have stood before they can vouch for it. See dirStamp(). */
 public const STAMP_SETTLE_SECONDS=3;

 /**
  * A directory's change stamp, and whether it is settled.
  *
  * Adding, removing or renaming an entry moves a directory's mtime and ctime,
  * so an unchanged stamp proves an unchanged list of entries -- provided the
  * stamp was already a few seconds old when it was read. Timestamps come in
  * ticks (a second through PHP, two seconds on FAT), and a change that lands
  * in the same tick as the one before it leaves the stamp where it was. One
  * that had stood for longer than a tick cannot be left behind like that.
  *
  * Read it before reading the directory: a change in between then shows up
  * later as a mismatch, never as a match against entries that have moved on.
  * And read it from the disk, not PHP's stat cache, which may hold one taken
  * earlier.
  *
  * @return array{0:string,1:bool}|null the stamp and whether it is settled; null if it cannot be read
  */
 public function dirStamp(string $dir): ?array {
  clearstatcache();$now=time();$st=@stat($dir);
  return $st===false?null:self::stampOf($st,$now);
 }

 /** @return array{0:string,1:bool} */
 private static function stampOf(array $st,int $now): array {
  return [$st['mtime'].':'.$st['ctime'],max((int)$st['mtime'],(int)$st['ctime'])<=$now-self::STAMP_SETTLE_SECONDS];
 }

 /**
  * search(), from and extending a record of what each directory held.
  *
  * The record maps every directory a walk has read to its dirStamp(), whether
  * that had settled, and its entries with their types. A directory whose stamp
  * still matches, and had settled, is not read again; any other is, and only
  * its own record is replaced -- a new photo in one folder costs that folder,
  * not the walk. The order of the walk depends on the tree alone, never on the
  * needle, so every search below any folder of the same storage reaches the
  * answer search() would, down to `scanned` and `truncated`. Result rows are
  * read from the disk, never from the record.
  *
  * $deadline stops the walk before it reads another directory from the disk,
  * never part way through one and never before it has read at least one that
  * was new to the record or had changed, so each call advances the record and
  * asking again always gets further.
  *
  * @param array|null $index what an earlier call returned for this storage root
  * @return array{0:array{results:list<array>,truncated:bool,incomplete:bool,scanned:int},1:array,2:bool}
  *   the answer; the record as it now stands; and whether this call changed it
  */
 public function searchWithIndex(string $path,string $needle,int $limit,int $maxNodes,?array $index,?float $deadline=null): array {
  $start=$this->existing($path);if(!is_dir($start))throw new RuntimeException('Directory not found',404);
  if(!self::recordingFits($index,$this->root))$index=['v'=>2,'root'=>$this->root,'dirs'=>[],'bytes'=>0];
  $needle=trim($needle);
  if($needle==='')return [['results'=>[],'truncated'=>false,'incomplete'=>false,'scanned'=>0],$index,false];
  $results=[];$scanned=0;$truncated=false;$incomplete=false;$changed=false;$read=0;
  $queue=[$start];
  for($head=0;$head<count($queue);$head++){
   $dir=$queue[$head];
   // Taken before the directory is read, and it carries the link count readDir() needs.
   clearstatcache();$now=time();$st=@stat($dir);
   if($st===false)continue;
   $stamp=self::stampOf($st,$now);
   $known=$index['dirs'][$dir]??null;
   if(!is_array($known)||count($known)!==4||$known[1]!==true||$known[0]!==$stamp[0]){
    // Reading a folder whose record had not settled is not progress: it has to
    // be read again every time until it settles, and if that could spend the
    // one read a call is allowed, a tree that has just changed would never get
    // past it.
    $progress=!is_array($known)||($known[1]??null)===true;
    if($progress&&$deadline!==null&&$read>0&&microtime(true)>$deadline){$truncated=$incomplete=true;break;}
    [$names,$types]=$this->readDir($dir,$st);
    if($progress)$read++;
    $joined=implode("\0",$names);
    $index['bytes']+=strlen($joined)-(is_array($known)?strlen((string)($known[2]??'')):0);
    $known=$index['dirs'][$dir]=[$stamp[0],$stamp[1],$joined,$types];
    $changed=true;
   }
   foreach($known[2]===''?[]:explode("\0",$known[2]) as $i=>$name){
    if(++$scanned>$maxNodes){$truncated=true;break 2;}
    $full=$dir.'/'.$name;$isDir=($known[3][$i]??'f')==='d';
    // An entry readDir() left unchecked is checked once it matters: a symlink
    // is never a result, and must not take a place among the first $limit.
    if(stripos($name,$needle)!==false&&($isDir||!is_link($full))){
     if(count($results)>=$limit){$truncated=true;break 2;}
     $results[]=$full;
    }
    if($isDir)$queue[]=$full;
   }
  }
  $rows=[];
  foreach($results as $full){$row=$this->resultRow($full);if($row!==null)$rows[]=$row;}
  usort($rows,fn($a,$b)=>$a['isDirectory']!==$b['isDirectory']?($a['isDirectory']?-1:1):strcasecmp($a['path'],$b['path']));
  return [['results'=>$rows,'truncated'=>$truncated,'incomplete'=>$incomplete,'scanned'=>$scanned],$index,$changed];
 }

 /**
  * One directory's entries for a walk -- those children() would give -- and a
  * type for each, 'd' or 'f', in scandir() order.
  *
  * What makes this cheap is the directory's link count. On the filesystems a
  * phone or a server keeps files on -- ext4 and f2fs, and Android's FUSE over
  * them -- a directory has two links plus one per subdirectory, so once that
  * many subdirectories have been found, whatever is left is not one and needs
  * no lstat(). Names that look like folders are checked first. A camera folder
  * of six thousand photos and no subfolders therefore costs nothing but its
  * scandir(), where the old walk made two system calls per photo -- the same
  * rule GNU find has relied on for decades.
  *
  * Where the rule cannot be trusted -- a link count below two, as btrfs and
  * NTFS report, or more subdirectories found than it allows -- every entry is
  * checked. An entry left unchecked is never descended into, so it cannot be a
  * way out of the root even if it were a symlink, and searchWithIndex() checks
  * one before it can be a result. It still counts towards `scanned`: symlinks
  * named like files are the one thing that makes that count differ from an
  * lstat() of everything, and Android's shared storage cannot hold a symlink.
  *
  * @param array $st the directory's own stat(), taken before it is read
  * @return array{0:list<string>,1:string}
  */
 private function readDir(string $dir,array $st): array {
  $atRoot=rtrim($dir,'/')===$this->root;
  $expected=(int)$st['nlink']>=2?(int)$st['nlink']-2:null;
  $names=[];
  foreach(scandir($dir)?:[] as $name){
   if($name==='.'||$name==='..')continue;
   if($atRoot&&self::isReservedRootName($name)){
    // Not listed, but still a subdirectory as far as the link count goes.
    if($expected!==null&&!is_link($dir.'/'.$name)&&is_dir($dir.'/'.$name))$expected--;
    continue;
   }
   $names[]=$name;
  }
  $types=array_fill(0,count($names),'f');$gone=[];$found=0;
  $first=[];$rest=[];
  foreach($names as $i=>$name){if(self::looksLikeFile($name))$rest[]=$i;else $first[]=$i;}
  foreach([$first,$rest] as $pass=>$indices){
   // More folders than the link count allows: the rule does not hold here.
   if($pass===1&&$expected!==null&&$found>$expected)$expected=null;
   foreach($indices as $i){
    if($pass===1&&$expected!==null&&$found>=$expected)break;
    $lst=@lstat($dir.'/'.$names[$i]);
    if($lst===false){$gone[$i]=true;continue;}
    $kind=$lst['mode']&0170000;
    if($kind===0120000){$gone[$i]=true;continue;}
    if($kind===0040000){$types[$i]='d';$found++;}
   }
  }
  $keptNames=[];$keptTypes='';
  foreach($names as $i=>$name)if(!isset($gone[$i])){$keptNames[]=$name;$keptTypes.=$types[$i];}
  return [$keptNames,$keptTypes];
 }

 /**
  * Whether a name reads as a file ("IMG_0042.jpg") rather than a folder
  * ("Album 1", "com.whatsapp", ".thumbnails", "v1.2"). It only orders the work
  * in readDir(); the link count decides what is skipped.
  */
 private static function looksLikeFile(string $name): bool {
  $dot=strrpos($name,'.');
  if($dot===false||$dot===0)return false;
  $ext=substr($name,$dot+1);
  return $ext!==''&&strlen($ext)<=5&&ctype_alnum($ext)&&!ctype_digit($ext);
 }

 /** A search result's row, or null if it has gone since the walk saw it. */
 private function resultRow(string $full): ?array {
  return file_exists($full)?$this->entry($full):null;
 }

 /** Whether a stored record is one searchWithIndex() made for this storage root. */
 private static function recordingFits(?array $index,string $root): bool {
  return $index!==null&&($index['v']??null)===2&&($index['root']??null)===$root
   &&is_array($index['dirs']??null)&&is_int($index['bytes']??null);
 }

 /** Recursive copy that refuses symlinks, mirroring deleteTree's contract. */
 public function copyTree(string $src,string $dst): void {
  $this->assertContained(str_replace('\\','/',$src));$this->assertContained(str_replace('\\','/',$dst));
  if(is_link($src))throw new RuntimeException('Symlinks cannot be copied',403);
  if(is_dir($src)){
   if(!is_dir($dst)&&!@mkdir($dst,0775,true)&&!is_dir($dst))throw new RuntimeException('Unable to create the destination directory',500);
   foreach(scandir($src)?:[] as $n){if($n==='.'||$n==='..')continue;$this->copyTree($src.'/'.$n,$dst.'/'.$n);}
   return;
  }
  if(!copy($src,$dst))throw new RuntimeException('Unable to copy '.basename($src),500);
 }

 /**
  * Every file at or below a path, for attributing what a copy just created.
  *
  * @return list<string> absolute paths
  */
 public function copiedFiles(string $path): array {
  if(is_link($path))return [];
  // A path that is not there produced [$path] and, once a partly-failed copy
  // started asking what landed, would have attributed a row to a file that
  // was never written.
  if(!file_exists($path))return [];
  if(!is_dir($path))return [$path];
  $out=[];$stack=[$path];
  while($stack){
   $dir=array_pop($stack);
   foreach(scandir($dir)?:[] as $n){
    if($n==='.'||$n==='..')continue;$full=$dir.'/'.$n;if(is_link($full))continue;
    is_dir($full)?$stack[]=$full:$out[]=$full;
   }
  }
  return $out;
 }

 /**
  * Total bytes and file count below a path.
  *
  * @return array{bytes:int,files:int}
  */
 public function measure(string $path): array {
  if(is_link($path))return ['bytes'=>0,'files'=>0];
  if(!is_dir($path))return ['bytes'=>(int)(filesize($path)?:0),'files'=>1];
  $bytes=0;$files=0;$stack=[$path];
  while($stack){
   $dir=array_pop($stack);
   foreach(scandir($dir)?:[] as $n){
    if($n==='.'||$n==='..')continue;$full=$dir.'/'.$n;if(is_link($full))continue;
    if(is_dir($full)){$stack[]=$full;continue;}
    $bytes+=(int)(filesize($full)?:0);$files++;
   }
  }
  return ['bytes'=>$bytes,'files'=>$files];
 }


 /**
  * One pass over the whole tree, producing everything the storage screen shows.
  *
  * Deliberately unbounded, unlike search(): a dashboard that stopped counting
  * early would report a number that is simply wrong, and "your disk is 60%
  * full" is only useful if it is true. The cost is paid once and cached by the
  * caller rather than reduced by guessing.
  *
  * The trash is measured separately and excluded from the browsable totals,
  * because it is space you can reclaim rather than space you are using.
  */
 public function storageReport(int $largestCount=10): array {
  $acc=['bytes'=>0,'files'=>0,'largest'=>[],'byType'=>[]];
  $folders=[];
  foreach($this->children($this->root) as $child){
   $before=[$acc['bytes'],$acc['files']];
   $this->accumulate($child,$acc,$largestCount);
   if(is_dir($child))$folders[]=['name'=>basename($child),'path'=>$this->relative($child),
    'bytes'=>$acc['bytes']-$before[0],'files'=>$acc['files']-$before[1]];
  }
  usort($folders,fn($a,$b)=>$b['bytes']<=>$a['bytes']);

  $largest=$acc['largest'];
  usort($largest,fn($a,$b)=>$b['bytes']<=>$a['bytes']);
  arsort($acc['byType']);

  // Summed from what each entry recorded when it was trashed, rather than by
  // measuring .trash: that would count the bookkeeping files as reclaimable
  // space and report a folder of one file as two.
  $trash=['bytes'=>0,'files'=>0,'entries'=>0];
  foreach($this->trashList() as $entry){
   $trash['bytes']+=(int)($entry['bytes']??0);
   $trash['files']+=(int)($entry['files']??0);
   $trash['entries']++;
  }

  return ['bytes'=>$acc['bytes'],'files'=>$acc['files'],'folders'=>$folders,
   'largest'=>array_slice($largest,0,$largestCount),
   'byType'=>$acc['byType'],'trash'=>$trash,
   'diskTotal'=>(int)(@disk_total_space($this->root)?:0),
   'diskFree'=>(int)(@disk_free_space($this->root)?:0),
   'measuredAt'=>gmdate('c')];
 }

 private function accumulate(string $path,array &$acc,int $largestCount): void {
  if(is_link($path))return;
  if(is_dir($path)){
   foreach($this->children($path) as $child)$this->accumulate($child,$acc,$largestCount);
   return;
  }
  $size=(int)(filesize($path)?:0);
  $acc['bytes']+=$size;$acc['files']++;
  $type=self::fileCategory($path);
  $acc['byType'][$type]=($acc['byType'][$type]??0)+$size;

  // Trimmed periodically rather than sorted on every file: a hundred thousand
  // files would otherwise mean a hundred thousand sorts.
  $acc['largest'][]=['name'=>basename($path),'path'=>$this->relative($path),'bytes'=>$size];
  if(count($acc['largest'])>$largestCount*8){
   usort($acc['largest'],fn($a,$b)=>$b['bytes']<=>$a['bytes']);
   $acc['largest']=array_slice($acc['largest'],0,$largestCount);
  }
 }

 /** Coarse grouping for "what is filling the disk", by extension. */
 public static function fileCategory(string $path): string {
  static $map=null;
  if($map===null){
   $map=[];
   foreach(['image'=>['jpg','jpeg','png','gif','webp','bmp','svg','avif','heic','tif','tiff'],
    'video'=>['mp4','webm','ogv','mov','m4v','avi','mkv','mpeg','mpg','3gp','ts','m2ts','mts'],
    'audio'=>['mp3','wav','ogg','oga','m4a','aac','flac','opus'],
    'document'=>['pdf','doc','docx','odt','xls','xlsx','ods','ppt','pptx','odp','txt','md','csv','rtf'],
    'archive'=>['zip','tar','gz','bz2','xz','7z','rar','iso']] as $group=>$exts)
    foreach($exts as $e)$map[$e]=$group;
  }
  $ext=strtolower(pathinfo($path,PATHINFO_EXTENSION));
  return $map[$ext]??'other';
 }

 /* ---- Trash ------------------------------------------------------------
  *
  * The trash lives inside the storage root so that moving a file into it is
  * always a same-filesystem rename -- an instant, atomic operation that
  * cannot half-finish, and cannot fail because the root is a separate mount.
  * sanitize() refuses to address it, so it is invisible to every other route.
  *
  * Layout, one directory per deletion:
  *   .trash/<id>/meta.json      what it was and where it came from
  *   .trash/<id>/payload/<name> the item itself, under its original name
  *
  * The payload subdirectory is what makes name collisions impossible: two
  * files called the same thing, and a file called "meta.json", all coexist.
  */

 private const TRASH_ID='/^[0-9]{8}-[0-9]{6}-[0-9a-f]{8}$/';

 public function trashRoot(): string {return $this->root.'/.trash';}

 /**
  * Move an item into the trash and return its recorded metadata.
  *
  * $attribution is the ledger's rows for the item (StorageLedger::rowsUnder()),
  * kept beside meta.json so restore() can hand the bytes back to whoever
  * uploaded them. $favorites is the same for the accounts that had starred it
  * (FavoriteRepository::rowsUnder()), so a restore brings those back too.
  */
 public function trash(string $realPath,?string $actor=null,array $attribution=[],array $favorites=[]): array {
  $realPath=str_replace('\\','/',$realPath);$this->assertContained($realPath);
  if(rtrim($realPath,'/')===$this->root)throw new RuntimeException('Storage root cannot be deleted',403);
  if(str_starts_with($realPath.'/',$this->trashRoot().'/'))throw new RuntimeException('That item is already in the trash',400);
  if(!file_exists($realPath))throw new RuntimeException('File or directory not found',404);

  // Recorded before the move, because afterwards the original path is gone.
  $original=$this->relative($realPath);
  $isDir=is_dir($realPath)&&!is_link($realPath);
  $measured=$this->measure($realPath);

  $id=gmdate('Ymd-His').'-'.bin2hex(random_bytes(4));
  $entry=$this->trashRoot().'/'.$id;
  // Suppressed because the return value is checked right here and turned
  // into an exception that says what failed and why; the raw warning is the
  // same fact with less context, and bootstrap's handler logs the throw.
  if(!@mkdir($entry.'/payload',0775,true))throw new RuntimeException('Unable to open the trash',500);

  $name=basename($realPath);
  if(!rename($realPath,$entry.'/payload/'.$name)){
   $this->deleteTree($entry);
   throw new RuntimeException('Unable to move '.$name.' to the trash',500);
  }

  $meta=['id'=>$id,'name'=>$name,'originalPath'=>$original,'isDirectory'=>$isDir,
   'bytes'=>$measured['bytes'],'files'=>$measured['files'],
   'deletedAt'=>gmdate('c'),'deletedBy'=>$actor];

  /*
   * A trash entry without readable metadata is worse than a failed delete.
   *
   * The payload has already been moved, and trashMeta() returns null for an
   * entry whose meta.json cannot be read -- so the item would vanish from
   * trashList(), trashPurge(), trashPurgeExpired() and the storage report at
   * once, while the caller was still told "Moved to trash". The bytes would
   * be unreachable through every route in the application.
   *
   * The realistic trigger is a full disk, which is exactly when someone is
   * deleting things. So the move is put back and the delete fails loudly,
   * leaving the file where the user last saw it.
   */
  if(file_put_contents($entry.'/meta.json',json_encode($meta,JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT))===false){
   if(!rename($entry.'/payload/'.$name,$realPath))
    throw new RuntimeException('Unable to record the deletion of '.$name.', and it could not be put back. It is in '.$this->relative($entry),500);
   $this->deleteTree($entry);
   throw new RuntimeException('Unable to record the deletion of '.$name.'; it was left where it was',500);
  }
  // Beside meta.json rather than in it: trashList() reads every meta.json and
  // hands it to the client, and per-account rows are nobody else's business.
  // Best effort -- without it a restore is merely unattributed, as before.
  if($attribution&&@file_put_contents($entry.'/attribution.json',json_encode(array_values($attribution),JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE))===false)
   error_log('[trash] could not keep the attribution of '.$original);
  // Beside it, and best effort, for the same reasons: which accounts starred
  // this is theirs alone, and losing it costs a favorite, never the file.
  if($favorites&&@file_put_contents($entry.'/favorites.json',json_encode(array_values($favorites),JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE))===false)
   error_log('[trash] could not keep the favorites of '.$original);
  return $meta;
 }

 /** @return list<array> newest deletion first */
 public function trashList(): array {
  $dir=$this->trashRoot();if(!is_dir($dir))return [];
  $out=[];
  foreach(scandir($dir)?:[] as $id){
   if($id==='.'||$id==='..'||!preg_match(self::TRASH_ID,$id))continue;
   $meta=$this->trashMeta($id);if($meta!==null)$out[]=$meta;
  }
  usort($out,fn($a,$b)=>strcmp((string)$b['deletedAt'],(string)$a['deletedAt']));
  return $out;
 }

 /**
  * Put a trashed item back, and report the path it landed on.
  *
  * Restore never overwrites and never fails on a busy name: if something now
  * occupies the original path the item is restored beside it with a suffix.
  * A missing parent folder is recreated, so restoring survives the folder
  * having been deleted too.
  */
 public function restore(string $id): array {
  $meta=$this->trashMeta($id);
  if($meta===null)throw new RuntimeException('That trash entry no longer exists',404);
  $payload=$this->trashRoot().'/'.$id.'/payload/'.$meta['name'];
  if(!file_exists($payload))throw new RuntimeException('The trashed item is missing from disk',410);

  $target=$this->sanitize((string)$meta['originalPath']);
  $parent=dirname($target);
  if(!is_dir($parent)&&!@mkdir($parent,0775,true)&&!is_dir($parent))throw new RuntimeException('Unable to recreate the original folder',500);
  $target=$this->freeName($target);

  if(!rename($payload,$target))throw new RuntimeException('Unable to restore '.$meta['name'],500);
  $attribution=json_decode((string)@file_get_contents($this->trashRoot().'/'.$id.'/attribution.json'),true);
  $favorites=json_decode((string)@file_get_contents($this->trashRoot().'/'.$id.'/favorites.json'),true);
  $this->deleteTree($this->trashRoot().'/'.$id);
  return ['path'=>$this->relative($target),'renamed'=>basename($target)!==$meta['name'],
   'originalPath'=>(string)$meta['originalPath'],'attribution'=>is_array($attribution)?$attribution:[],
   'favorites'=>is_array($favorites)?$favorites:[]];
 }

 /** Permanently remove one trash entry, or every entry when $id is null. */
 public function trashPurge(?string $id=null): int {
  if($id!==null){
   if(!preg_match(self::TRASH_ID,$id))throw new RuntimeException('Unknown trash entry',404);
   $entry=$this->trashRoot().'/'.$id;
   if(!is_dir($entry))throw new RuntimeException('That trash entry no longer exists',404);
   $this->deleteTree($entry);return 1;
  }
  $n=0;foreach($this->trashList() as $meta){$this->deleteTree($this->trashRoot().'/'.$meta['id']);$n++;}
  return $n;
 }

 /** Drop entries deleted longer than $days ago. 0 disables expiry. */
 public function trashPurgeExpired(int $days): int {
  if($days<=0)return 0;
  $cutoff=time()-$days*86400;$n=0;
  foreach($this->trashList() as $meta){
   $at=strtotime((string)$meta['deletedAt']);
   if($at!==false&&$at<$cutoff){$this->deleteTree($this->trashRoot().'/'.$meta['id']);$n++;}
  }
  return $n;
 }

 private function trashMeta(string $id): ?array {
  if(!preg_match(self::TRASH_ID,$id))return null;
  $file=$this->trashRoot().'/'.$id.'/meta.json';
  if(!is_file($file))return null;
  $meta=json_decode((string)file_get_contents($file),true);
  if(!is_array($meta)||!isset($meta['name'],$meta['originalPath']))return null;
  $meta['id']=$id;
  return $meta;
 }

 /** The given path if free, otherwise the same name with " (n)" appended. */
 public function freeName(string $target): string {
  if(!file_exists($target))return $target;
  $dir=dirname($target);$name=basename($target);
  $ext=pathinfo($name,PATHINFO_EXTENSION);
  $stem=$ext===''?$name:substr($name,0,-(strlen($ext)+1));
  for($i=2;$i<1000;$i++){
   $candidate=$dir.'/'.$stem.' ('.$i.')'.($ext===''?'':'.'.$ext);
   if(!file_exists($candidate))return $candidate;
  }
  throw new RuntimeException('Too many items with that name',409);
 }

 /**
  * Whether two existing paths name one file.
  *
  * On case-insensitive storage -- Android's shared storage, macOS, Windows --
  * "a.txt" and "A.txt" are the same file, so a case-only rename or move finds
  * its own source already at the destination, and treating that as a
  * collision either picked "a (2).txt" or displaced the source itself. Linux
  * realpath() keeps the spelling it was given, so after comparing paths this
  * compares device and inode. A hard-linked file is never "the same": POSIX
  * rename() between two links to one inode does nothing and reports success.
  */
 public function isSameFile(string $a,string $b): bool {
  if(!file_exists($a)||!file_exists($b))return false;
  $ra=realpath($a);$rb=realpath($b);
  if($ra!==false&&$ra===$rb)return true;
  $x=@stat($a);$y=@stat($b);
  if($x===false||$y===false||(int)$x['ino']===0)return false;
  if($x['dev']!==$y['dev']||$x['ino']!==$y['ino'])return false;
  return is_dir($a)||(int)$x['nlink']===1;
 }

 private function assertContained(string $path): void {
  $path=rtrim(str_replace('\\','/',$path),'/');
  if($path!==$this->root&&!str_starts_with($path,$this->root.'/'))throw new RuntimeException('Path escapes the configured storage root',403);
 }
 private function assertNoSymlinkTraversal(string $candidate): void {
  if($this->pathContainsSymlink($candidate))throw new RuntimeException('Symlink traversal is not allowed',403);
 }
 private function pathContainsSymlink(string $candidate): bool {
  $candidate=str_replace('\\','/',$candidate);$this->assertContained($candidate);
  $rel=ltrim(substr($candidate,strlen($this->root)),'/');
  if($rel==='')return false;$cur=$this->root;
  foreach(explode('/',$rel) as $part){$cur.='/'.$part;if(is_link($cur))return true;if(!file_exists($cur))break;}
  return false;
 }
}
