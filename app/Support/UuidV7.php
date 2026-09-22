<?php
declare(strict_types=1);
namespace Ecrm\Support;
final class UuidV7 { public static function generate(): string { $ms=(int)floor(microtime(true)*1000); $b=random_bytes(16); for($i=5;$i>=0;$i--){$b[$i]=chr($ms&0xff);$ms>>=8;} $b[6]=chr((ord($b[6])&0x0f)|0x70);$b[8]=chr((ord($b[8])&0x3f)|0x80);$h=bin2hex($b);return substr($h,0,8).'-'.substr($h,8,4).'-'.substr($h,12,4).'-'.substr($h,16,4).'-'.substr($h,20); } }
