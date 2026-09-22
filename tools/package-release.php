<?php
declare(strict_types=1);
$root=dirname(__DIR__); $release=$root.'/release';
function rrmdir(string $d):void{if(!is_dir($d))return; foreach(scandir($d)?:[] as $f){if($f==='.'||$f==='..')continue;$p="$d/$f";is_dir($p)?rrmdir($p):unlink($p);}rmdir($d);}
function copytree(string $s,string $d):void{if(!is_dir($s))return;if(!is_dir($d))mkdir($d,0775,true);foreach(scandir($s)?:[] as $f){if($f==='.'||$f==='..')continue;$a="$s/$f";$b="$d/$f";is_dir($a)?copytree($a,$b):copy($a,$b);}}
rrmdir($release); mkdir($release.'/public',0775,true); mkdir($release.'/private',0775,true);
copytree($root.'/public',$release.'/public'); copytree($root.'/dist',$release.'/public/assets'); copytree($root.'/app',$release.'/private/app'); copytree($root.'/vendor',$release.'/private/vendor');
$sha=trim((string)getenv('GITHUB_SHA')) ?: 'local';
$meta=['app'=>'ecrm','version'=>'0.1.0','source_sha'=>$sha,'built_at'=>gmdate(DATE_ATOM),'schema'=>1,'persistent_paths'=>['data','uploads','audit','users','config','jobs','locks','backups']];
file_put_contents($release.'/RELEASE.json',json_encode($meta,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)."\n");
