<?php
declare(strict_types=1);
namespace CloudHub\Services;
use CloudHub\Helpers\Http;
final class Authorization {
 public const VIEWER='viewer',EDITOR='editor',ADMIN='admin';
 public static function role():string{$r=(string)($_SESSION['role']??self::VIEWER);return in_array($r,[self::VIEWER,self::EDITOR,self::ADMIN],true)?$r:self::VIEWER;}
 public static function requireRead():void{Auth::requireUser();}
 public static function requireWrite():void{Auth::requireUser();if(!in_array(self::role(),[self::EDITOR,self::ADMIN],true))Http::error(403,'FORBIDDEN','Write permission is required');}
 public static function requireAdmin():void{Auth::requireUser();if(self::role()!==self::ADMIN)Http::error(403,'FORBIDDEN','Administrator permission is required');}
 public static function canWrite():bool{return in_array(self::role(),[self::EDITOR,self::ADMIN],true);}
 public static function isAdmin():bool{return self::role()===self::ADMIN;}
}
