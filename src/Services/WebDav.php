<?php
declare(strict_types=1);
namespace CloudHub\Services;
use CloudHub\Helpers\Http;
function xml_escape(string $s): string{return htmlspecialchars($s,ENT_XML1|ENT_QUOTES,'UTF-8');}
/**
 * WebDAV, under the same session, CSRF and role rules as the rest of the API.
 *
 * $hooks keep the front controller's bookkeeping (upload ledger, share links,
 * audit trail) in step with changes made here: removed(string $relative),
 * moved(string $from, string $to), attribution(string $relative) for the
 * ledger rows a trash entry keeps, and for PUT the API's upload rules --
 * fits(int $bytes) throws when a quota or the store limit would be exceeded,
 * replacing(string $full) keeps what is about to be overwritten, and
 * stored(string $relative, int $bytes) records who uploaded it.
 */
function handle_webdav(FileService $fs,array $config,string $path,string $method,array $hooks=[]): never {
 // The router already percent-decoded the path once; a second decode here
 // broke names that legitimately contain "%".
 $rel=preg_replace('#^/webdav/?#','',$path)??'';
 $removed=$hooks['removed']??null;$moved=$hooks['moved']??null;$attribution=$hooks['attribution']??null;
 // Defence in depth: the front controller already requires CSRF and the write
 // capability for every non-read verb; the handler enforces the role itself.
 if(!in_array($method,['OPTIONS','PROPFIND','GET','HEAD'],true))Authorization::requireWrite();
 $hrefBase=Http::encodePath(Http::basePath()).'/webdav';
 $href=static fn(string $relative,bool $dir):string=>$relative==='/'?$hrefBase.'/':$hrefBase.Http::encodePath($relative).($dir?'/':'');
 if($method==='OPTIONS'){header('Allow: OPTIONS, PROPFIND, GET, HEAD, PUT, DELETE, MKCOL, MOVE');header('DAV: 1');header('MS-Author-Via: DAV');http_response_code(200);exit;}

 if($method==='PROPFIND'){
  try{$full=$fs->existing($rel);}catch(\RuntimeException $e){http_response_code($e->getCode()===404?404:403);exit;}
  $depth=$_SERVER['HTTP_DEPTH']??'1';$items=[[$full,$href($fs->relative($full),is_dir($full))]];
  // childPaths() applies the listing rules (symlinks skipped, CloudHub's own
  // .trash/.versions/.uploads never advertised); hrefs carry the base path and
  // are percent-encoded per segment.
  if(is_dir($full)&&$depth!=='0')foreach($fs->childPaths($full) as $child)$items[]=[$child,$href($fs->relative($child),is_dir($child))];
  $xml='<?xml version="1.0" encoding="UTF-8"?><d:multistatus xmlns:d="DAV:">';
  foreach($items as [$f,$href]){$dir=is_dir($f);$xml.='<d:response><d:href>'.xml_escape($href).'</d:href><d:propstat><d:prop><d:getlastmodified>'.gmdate('D, d M Y H:i:s \G\M\T',filemtime($f)?:time()).'</d:getlastmodified><d:creationdate>'.gmdate('c',filectime($f)?:time()).'</d:creationdate>'.($dir?'<d:resourcetype><d:collection/></d:resourcetype>':'<d:resourcetype/><d:getcontentlength>'.filesize($f).'</d:getcontentlength><d:getcontenttype>'.xml_escape(mime_type($f)).'</d:getcontenttype>').'</d:prop><d:status>HTTP/1.1 200 OK</d:status></d:propstat></d:response>';}
  $xml.='</d:multistatus>';http_response_code(207);header('Content-Type: application/xml; charset=utf-8');echo $xml;exit;
 }
 if($method==='GET'||$method==='HEAD'){
  try{$full=$fs->existing($rel);}catch(\RuntimeException $e){http_response_code(404);exit;}
  if(!is_file($full)){http_response_code(405);exit;}
  $size=@filesize($full);
  header('Content-Type: '.mime_type($full));
  // User files are never rendered as a page on this origin: WebDAV clients
  // ignore these headers, while a browser following a link downloads the file
  // instead of running any markup it contains.
  header('Content-Disposition: attachment; filename="'.str_replace(['"',"\r","\n"],'_',basename($full)).'"');
  header("Content-Security-Policy: default-src 'none'; sandbox");
  header('X-Content-Type-Options: nosniff');
  if($size!==false)header('Content-Length: '.$size);
  if($method==='GET')readfile($full);
  exit;
 }
 if($config['read_only']){http_response_code(403);exit;}

 if($method==='PUT'){
  try{$full=file_exists($fs->sanitize($rel))?$fs->existing($rel):$fs->destination($rel);}catch(\RuntimeException $e){http_response_code(in_array($e->getCode(),[403,404],true)?409:400);exit;}
  $exists=file_exists($full);if($exists&&!$config['allow_overwrite']){http_response_code(409);exit;}if($exists&&is_dir($full)){http_response_code(405);exit;}
  // A PUT is an upload and answers to the same rules; without these it
  // stepped round the quota and the ledger, and in Cloudhub-2 overwrote a
  // file without keeping the version every other upload keeps.
  $fits=$hooks['fits']??null;$replacing=$hooks['replacing']??null;$stored=$hooks['stored']??null;
  $refuse=static function(\RuntimeException $e):never{http_response_code($e->getCode()===507?507:500);exit;};
  $declared=(string)($_SERVER['CONTENT_LENGTH']??'');
  if($fits&&ctype_digit($declared)){try{$fits((int)$declared);}catch(\RuntimeException $e){$refuse($e);}}
  $in=fopen('php://input','rb');$tmp=$full.'.upload-'.bin2hex(random_bytes(6));
  $out=@fopen($tmp,'xb');if(!$in||!$out){if(is_resource($in))fclose($in);http_response_code(500);exit;}
  $ok=stream_copy_to_stream($in,$out)!==false;fclose($out);fclose($in);
  $size=(int)(@filesize($tmp)?:0);
  // A chunked body never said how large it was, so it is measured instead.
  if($ok&&$fits&&!ctype_digit($declared)){try{$fits($size);}catch(\RuntimeException $e){@unlink($tmp);$refuse($e);}}
  if($ok&&$exists&&$replacing){try{$replacing($full);}catch(\RuntimeException){@unlink($tmp);http_response_code(500);exit;}}
  if(!$ok||!@rename($tmp,$full)){@unlink($tmp);http_response_code(500);exit;}
  if($stored)$stored($fs->relative($full),$size);
  http_response_code($exists?204:201);exit;
 }
 if($method==='DELETE'){
  if(!$config['allow_delete']){http_response_code(403);exit;}
  try{
   $full=$fs->existing($rel);$gone=$fs->relative($full);
   // Same rule as the API's delete: to the trash unless the deployment opted out.
   if($config['trash_enabled']??false)$fs->trash($full,Auth::user()['username']??null,$attribution?$attribution($gone):[]);else $fs->deleteTree($full);
  }catch(\RuntimeException $e){http_response_code($e->getCode()===404?404:($e->getCode()===500?500:403));exit;}
  if($removed)$removed($gone);
  http_response_code(204);exit;
 }
 if($method==='MKCOL'){
  try{$full=$fs->destination($rel);}catch(\RuntimeException $e){http_response_code(409);exit;}
  if(file_exists($full)){http_response_code(405);exit;}if(!mkdir($full,0775)){http_response_code(500);exit;}http_response_code(201);exit;
 }
 if($method==='MOVE'){
  $dest=$_SERVER['HTTP_DESTINATION']??'';if(!$dest){http_response_code(400);exit;}
  // The Destination header is an encoded URL that includes any base path.
  $dp=rawurldecode(parse_url($dest,PHP_URL_PATH)?:'');
  $base=Http::basePath();
  if($base!==''&&($dp===$base||str_starts_with($dp,$base.'/')))$dp=substr($dp,strlen($base));
  if(!preg_match('#^/webdav(?:/|$)#',$dp)){http_response_code(403);exit;}
  try{$full=$fs->existing($rel);$new=$fs->destination(preg_replace('#^/webdav/?#','',$dp)??'');}catch(\RuntimeException $e){http_response_code($e->getCode()===404?404:403);exit;}
  // A destination that is the source "exists" only because it is the source:
  // displacing it trashed or deleted the file, and the rename then failed.
  // The same path is refused (RFC 4918 9.9.4); the same file under another
  // spelling -- a case-only rename on case-insensitive storage -- just moves.
  $same=$fs->isSameFile($full,$new);
  if($same&&$fs->relative($full)===$fs->relative($new)){http_response_code(403);exit;}
  if(!$same&&is_dir($full)&&str_starts_with($new.'/',rtrim($full,'/').'/')){http_response_code(409);exit;}
  $exists=!$same&&file_exists($new);if($exists&&($_SERVER['HTTP_OVERWRITE']??'T')==='F'){http_response_code(412);exit;}if($exists&&!$config['allow_overwrite']){http_response_code(409);exit;}
  $from=$fs->relative($full);$to=$fs->relative($new);
  if($exists){
   // An overwrite displaces what was there; like any delete it goes to the
   // trash unless the deployment opted out.
   try{if($config['trash_enabled']??false)$fs->trash($new,Auth::user()['username']??null,$attribution?$attribution($to):[]);else $fs->deleteTree($new);}catch(\RuntimeException){http_response_code(500);exit;}
   if($removed)$removed($to);
  }
  if(!rename($full,$new)){http_response_code(500);exit;}
  if($moved)$moved($from,$to);
  http_response_code($exists?204:201);exit;
 }
 http_response_code(405);exit;
}
