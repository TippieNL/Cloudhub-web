<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/Services/FileService.php';
use CloudHub\Services\FileService;
$base=sys_get_temp_dir().'/cloudhub-p3-'.bin2hex(random_bytes(5));mkdir($base);mkdir($base.'/safe');file_put_contents($base.'/safe/a.txt','ok');
file_put_contents($base.'/safe/Report%20(1).txt','literal-percent');
$outside=sys_get_temp_dir().'/cloudhub-out-'.bin2hex(random_bytes(5));mkdir($outside);file_put_contents($outside.'/secret.txt','secret');
$fs=new FileService(['root_dir'=>$base,'read_only'=>false]);
$pass=0;$fail=0;
function check($name,$fn){global $pass,$fail;try{$ok=$fn();}catch(Throwable){$ok=false;}echo ($ok?'[PASS] ':'[FAIL] ').$name.PHP_EOL;$ok?$pass++:$fail++;}
check('normal existing file',fn()=>$fs->existing('/safe/a.txt')===realpath($base.'/safe/a.txt'));
check('dot-dot rejected',function()use($fs){try{$fs->sanitize('/safe/../x');return false;}catch(RuntimeException){return true;}});
// The traversal defence is the literal ".." segment check plus realpath
// containment below, not a decode: callers hand sanitize() an already-decoded
// path (PHP decodes $_GET, the router decodes the URL once), so decoding again
// only broke real filenames -- a file saved as "Report%20(1).txt" could be
// listed but never opened, because sanitize() turned the %20 into a space.
check('a real dot-dot segment is still rejected',function()use($fs){try{$fs->sanitize('/safe/../x');return false;}catch(RuntimeException){return true;}});
// A literal "%2e%2e" is a directory name, not "..": the filesystem never
// decodes it, so it stays safely inside the root rather than being refused.
check('a percent-encoded name is treated as a literal name',fn()=>$fs->sanitize('/safe/%2e%2e/x')===$base.'/safe/%2e%2e/x');
// The bug the decode caused: a filename that legitimately contains a percent
// escape resolves to itself and can be opened.
check('a filename containing a percent escape is reachable',fn()=>$fs->existing('/safe/Report%20(1).txt')===realpath($base.'/safe/Report%20(1).txt'));
// isSameFile(): renaming or moving a file onto its own name is not a
// collision. "name (2)" was picked for an unchanged rename, and WebDAV's MOVE
// displaced -- trashed or deleted -- the source itself.
check('a path is the same file as itself',fn()=>$fs->isSameFile($base.'/safe/a.txt',$base.'/safe/a.txt'));
check('another spelling of one path is the same file',fn()=>$fs->isSameFile($base.'/safe/a.txt',$base.'/safe/./a.txt'));
check('two different files are not the same file',fn()=>!$fs->isSameFile($base.'/safe/a.txt',$base.'/safe/Report%20(1).txt'));
check('a missing path is never the same file',fn()=>!$fs->isSameFile($base.'/safe/a.txt',$base.'/safe/missing.txt'));
// rename() between two hard links to one inode does nothing and reports
// success, so a hard link must count as a collision, not as "the same".
if(function_exists('link')&&@link($base.'/safe/a.txt',$base.'/safe/linked.txt')){
 check('a hard link is not treated as the same file',fn()=>!$fs->isSameFile($base.'/safe/linked.txt',$base.'/safe/a.txt'));
}
check('drive path rejected',function()use($fs){try{$fs->sanitize('C:\\Windows\\x');return false;}catch(RuntimeException){return true;}});
check('root delete rejected',function()use($fs,$base){try{$fs->deleteTree(realpath($base));return false;}catch(RuntimeException){return true;}});
if(function_exists('symlink')&&@symlink($outside,$base.'/escape')){
 check('symlink traversal rejected',function()use($fs){try{$fs->existing('/escape/secret.txt');return false;}catch(RuntimeException){return true;}});
}
function rmrf($p){if(is_link($p)||is_file($p)){@unlink($p);return;}if(is_dir($p)){foreach(scandir($p)?:[] as $n)if($n!=='.'&&$n!=='..')rmrf($p.'/'.$n);@rmdir($p);}}
rmrf($base);rmrf($outside);exit($fail?1:0);
