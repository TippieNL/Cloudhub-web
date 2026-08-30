<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/Services/FileService.php';
use CloudHub\Services\FileService;
$base=sys_get_temp_dir().'/cloudhub-p3-'.bin2hex(random_bytes(5));mkdir($base);mkdir($base.'/safe');file_put_contents($base.'/safe/a.txt','ok');
$outside=sys_get_temp_dir().'/cloudhub-out-'.bin2hex(random_bytes(5));mkdir($outside);file_put_contents($outside.'/secret.txt','secret');
$fs=new FileService(['root_dir'=>$base,'read_only'=>false]);
$pass=0;$fail=0;
function check($name,$fn){global $pass,$fail;try{$ok=$fn();}catch(Throwable){$ok=false;}echo ($ok?'[PASS] ':'[FAIL] ').$name.PHP_EOL;$ok?$pass++:$fail++;}
check('normal existing file',fn()=>$fs->existing('/safe/a.txt')===realpath($base.'/safe/a.txt'));
check('dot-dot rejected',function()use($fs){try{$fs->sanitize('/safe/../x');return false;}catch(RuntimeException){return true;}});
check('encoded traversal rejected',function()use($fs){try{$fs->sanitize('/safe/%2e%2e/x');return false;}catch(RuntimeException){return true;}});
check('drive path rejected',function()use($fs){try{$fs->sanitize('C:\\Windows\\x');return false;}catch(RuntimeException){return true;}});
check('root delete rejected',function()use($fs,$base){try{$fs->deleteTree(realpath($base));return false;}catch(RuntimeException){return true;}});
if(function_exists('symlink')&&@symlink($outside,$base.'/escape')){
 check('symlink traversal rejected',function()use($fs){try{$fs->existing('/escape/secret.txt');return false;}catch(RuntimeException){return true;}});
}
function rmrf($p){if(is_link($p)||is_file($p)){@unlink($p);return;}if(is_dir($p)){foreach(scandir($p)?:[] as $n)if($n!=='.'&&$n!=='..')rmrf($p.'/'.$n);@rmdir($p);}}
rmrf($base);rmrf($outside);exit($fail?1:0);
