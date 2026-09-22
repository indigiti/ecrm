<?php
declare(strict_types=1);

$root = dirname(__DIR__);
if (!defined('ECRM_PRIVATE_ROOT')) {
    define('ECRM_PRIVATE_ROOT', $root);
}
require $root . '/app/bootstrap.php';

use Ecrm\Integrity\IntegrityVerifier;
use Ecrm\Storage\AtomicJsonStore;
use Ecrm\Support\Runtime;

$result = (new IntegrityVerifier(
    new AtomicJsonStore(Runtime::dataRoot()),
    Runtime::auditRoot()
))->verify();

echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($result['ok'] ? 0 : 1);
