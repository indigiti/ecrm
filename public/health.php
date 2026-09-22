<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
$private = dirname(__DIR__) . '/private';
$storage = $private . '/data';
$status = is_dir($storage) && is_readable($storage) && is_writable($storage) ? 'ok' : 'attention';
http_response_code($status === 'ok' ? 200 : 503);
echo json_encode(['status'=>$status,'app'=>'ecrm','version'=>'0.1.0','storage'=>$status], JSON_UNESCAPED_SLASHES);
