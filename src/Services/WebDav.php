<?php
declare(strict_types=1);
namespace CloudHub\Services;
function xml_escape(string $s): string{return htmlspecialchars($s,ENT_XML1|ENT_QUOTES,'UTF-8');}
function handle_webdav(FileService $fs,array $config,string $path,string $method): never {
 $rel=rawurldecode(preg_replace('#^/webdav/?#','',$path)??'');
 if($method==='OPTIONS'){header('Allow: OPTIONS, PROPFIND, GET, PUT, DELETE, MKCOL, MOVE');header('DAV: 1');header('MS-Author-Via: DAV');http_response_code(200);exit;}

 if($method==='PROPFIND'){
  try{$full=$fs->existing($rel);}catch(\RuntimeException $e){http_response_code($e->getCode()===404?404:403);exit;}
  $depth=$_SERVER['HTTP_DEPTH']??'1';$items=[[$full,'/webdav/'.ltrim($rel,'/')]];
  if(is_dir($full)&&$depth!=='0')foreach(scandir($full)?:[] as $n)if($n!=='.'&&$n!=='..'&&!is_link($full.'/'.$n))$items[]=[$full.'/'.$n,rtrim('/webdav/'.ltrim($rel,'/'),'/').'/'.rawurlencode($n)];
  $xml='<?xml version="1.0" encoding="UTF-8"?><d:multistatus xmlns:d="DAV:">';
  foreach($items as [$f,$href]){$dir=is_dir($f);$xml.='<d:response><d:href>'.xml_escape($href?:'/webdav/').'</d:href><d:propstat><d:prop><d:getlastmodified>'.gmdate('D, d M Y H:i:s \G\M\T',filemtime($f)?:time()).'</d:getlastmodified><d:creationdate>'.gmdate('c',filectime($f)?:time()).'</d:creationdate>'.($dir?'<d:resourcetype><d:collection/></d:resourcetype>':'<d:resourcetype/><d:getcontentlength>'.filesize($f).'</d:getcontentlength><d:getcontenttype>'.xml_escape(mime_type($f)).'</d:getcontenttype>').'</d:prop><d:status>HTTP/1.1 200 OK</d:status></d:propstat></d:response>';}
  $xml.='</d:multistatus>';http_response_code(207);header('Content-Type: application/xml; charset=utf-8');echo $xml;exit;
 }
 if($method==='GET'){try{$full=$fs->existing($rel);}catch(\RuntimeException $e){http_response_code(404);exit;}if(!is_file($full)){http_response_code(405);exit;}header('Content-Type: '.mime_type($full));header('Content-Length: '.filesize($full));readfile($full);exit;}
 if($config['read_only']){http_response_code(403);exit;}

 if($method==='PUT'){
  try{$full=file_exists($fs->sanitize($rel))?$fs->existing($rel):$fs->destination($rel);}catch(\RuntimeException $e){http_response_code(in_array($e->getCode(),[403,404],true)?409:400);exit;}
  $exists=file_exists($full);if($exists&&!$config['allow_overwrite']){http_response_code(409);exit;}if($exists&&is_dir($full)){http_response_code(405);exit;}
  $in=fopen('php://input','rb');$tmp=$full.'.upload-'.bin2hex(random_bytes(6));
  $out=@fopen($tmp,'xb');if(!$in||!$out){if(is_resource($in))fclose($in);http_response_code(500);exit;}
  $ok=stream_copy_to_stream($in,$out)!==false;fclose($out);fclose($in);
  if(!$ok||!@rename($tmp,$full)){@unlink($tmp);http_response_code(500);exit;}http_response_code($exists?204:201);exit;
 }
 if($method==='DELETE'){
  if(!$config['allow_delete']){http_response_code(403);exit;}
  try{$full=$fs->existing($rel);$fs->deleteTree($full);}catch(\RuntimeException $e){http_response_code($e->getCode()===404?404:403);exit;}http_response_code(204);exit;
 }
 if($method==='MKCOL'){
  try{$full=$fs->destination($rel);}catch(\RuntimeException $e){http_response_code(409);exit;}
  if(file_exists($full)){http_response_code(405);exit;}if(!mkdir($full,0775)){http_response_code(500);exit;}http_response_code(201);exit;
 }
 if($method==='MOVE'){
  $dest=$_SERVER['HTTP_DESTINATION']??'';if(!$dest){http_response_code(400);exit;}
  try{$full=$fs->existing($rel);$dp=parse_url($dest,PHP_URL_PATH)?:'';$destRel=rawurldecode(preg_replace('#^/webdav/?#','',$dp)??'');$new=$fs->destination($destRel);}catch(\RuntimeException $e){http_response_code($e->getCode()===404?404:403);exit;}
  $exists=file_exists($new);if($exists&&($_SERVER['HTTP_OVERWRITE']??'T')==='F'){http_response_code(412);exit;}if($exists&&!$config['allow_overwrite']){http_response_code(409);exit;}
  if($exists){try{$fs->deleteTree($new);}catch(\RuntimeException){http_response_code(500);exit;}}
  if(!rename($full,$new)){http_response_code(500);exit;}http_response_code($exists?204:201);exit;
 }
 http_response_code(405);exit;
}
