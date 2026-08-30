<?php
declare(strict_types=1);
namespace CloudHub\Helpers;
final class Http {
 private static ?string $requestId=null;
 public static function basePath(): string {
  $script=str_replace('\\','/',(string)($_SERVER['SCRIPT_NAME']??''));
  $dir=rtrim(str_replace('\\','/',dirname($script)),'/');
  if($dir==='.'||$dir==='/')return '';
  // public/index.php may be included by the project-root index.php.
  if(str_ends_with($dir,'/public'))$dir=substr($dir,0,-7);
  return $dir;
 }
 /**
  * URL prefix for the static files under public/assets.
  *
  * basePath() deliberately strips a trailing /public so that application
  * routes stay portable, but the static files really do live inside public/.
  * Whether the browser must ask for them as /public/assets or as plain
  * /assets depends on where the document root was pointed, and SCRIPT_NAME
  * cannot tell those layouts apart: it is '/index.php' either way. The
  * executing file's location can, so SCRIPT_FILENAME decides.
  *
  *   docroot = project root => runs <project>/index.php        => /public/assets
  *   docroot = public/      => runs <project>/public/index.php => /assets
  *
  * Emitting basePath().'/public/assets' unconditionally 404s whenever the
  * document root is public/, which is the layout the README recommends first.
  */
 public static function assetBase(): string {
  $base=self::basePath();
  $script=(string)($_SERVER['SCRIPT_FILENAME']??'');
  if($script==='')return $base.'/public';
  $dir=str_replace('\\','/',(string)(realpath(dirname($script))?:dirname($script)));
  $public=str_replace('\\','/',(string)(realpath(dirname(__DIR__,2).'/public')?:dirname(__DIR__,2).'/public'));
  // The entry point already sits inside public/, so assets are siblings of it.
  return rtrim($dir,'/')===rtrim($public,'/')?$base:$base.'/public';
 }
 public static function requestPath(string $basePath=''): string {
  // CloudHub's browser client uses the portable front-controller form:
  // /Cloud-File-Hub-PHP/?route=/api/files/list&path=...
  // Prefer that explicit virtual route when present.
  $route=$_GET['route']??null;
  if(is_string($route)&&$route!==''){
   $route=rawurldecode($route);
   if(str_contains($route,"\0"))return '/';
   return '/'.ltrim($route,'/');
  }

  // Also support rewritten/direct paths when the web server provides them.
  $uri=(string)($_SERVER['REQUEST_URI']??'/');
  $path=parse_url($uri,PHP_URL_PATH);
  if(!is_string($path)||$path==='')$path='/';
  $path=rawurldecode($path);
  if($basePath!==''&&($path===$basePath||str_starts_with($path,$basePath.'/'))){
   $path=substr($path,strlen($basePath));
  }
  if($path===''||$path==='/index.php')$path='/';
  return '/'.ltrim($path,'/');
 }
 public static function requestId():string{return self::$requestId??=bin2hex(random_bytes(8));}
 public static function body(int $max=1048576):array{
  $type=strtolower(trim(explode(';',(string)($_SERVER['CONTENT_TYPE']??''))[0]));
  if($type!==''&&$type!=='application/json')self::error(415,'UNSUPPORTED_MEDIA_TYPE','Expected application/json');
  if((int)($_SERVER['CONTENT_LENGTH']??0)>$max)self::error(413,'REQUEST_TOO_LARGE','Request body is too large');
  $raw=file_get_contents('php://input',false,null,0,$max+1);if($raw===false)self::error(400,'INVALID_REQUEST','Unable to read request body');
  if(strlen($raw)>$max)self::error(413,'REQUEST_TOO_LARGE','Request body is too large');if(trim($raw)==='')return [];
  try{$v=json_decode($raw,true,64,JSON_THROW_ON_ERROR);}catch(\JsonException){self::error(400,'INVALID_JSON','Request body contains invalid JSON');}
  if(!is_array($v)||array_is_list($v))self::error(400,'INVALID_JSON','JSON request body must be an object');return $v;
 }
 public static function error(int $status,string $code,string $message,array $details=[]):never{
  http_response_code($status);header('Content-Type: application/json; charset=utf-8');header('Cache-Control: no-store');header('X-Request-ID: '.self::requestId());
  $x=['error'=>['code'=>$code,'message'=>$message],'requestId'=>self::requestId()];if($details)$x['error']['details']=$details;echo json_encode($x,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);exit;
 }
 public static function json(mixed $data,int $status=200):never{
  http_response_code($status);header('Content-Type: application/json; charset=utf-8');header('Cache-Control: no-store');header('X-Request-ID: '.self::requestId());echo json_encode($data,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);exit;
 }
 public static function string(array $b,string $key,int $min=1,int $max=255):string{
  $v=$b[$key]??null;if(!is_string($v))self::error(422,'VALIDATION_FAILED',"$key must be a string");$v=trim($v);$n=function_exists('mb_strlen')?mb_strlen($v):strlen($v);if($n<$min||$n>$max)self::error(422,'VALIDATION_FAILED',"$key must be between $min and $max characters");return $v;
 }
 public static function optionalInt(array $b,string $key,int $min,int $max,?int $default=null):?int{
  if(!array_key_exists($key,$b))return $default;$v=filter_var($b[$key],FILTER_VALIDATE_INT);if($v===false||$v<$min||$v>$max)self::error(422,'VALIDATION_FAILED',"$key is outside the allowed range");return(int)$v;
 }
}
