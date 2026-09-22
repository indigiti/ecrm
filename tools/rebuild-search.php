<?php
declare(strict_types=1);

$root = dirname(__DIR__);
if (!defined('ECRM_PRIVATE_ROOT')) {
    define('ECRM_PRIVATE_ROOT', $root);
}
require $root . '/app/bootstrap.php';

use Ecrm\Search\SearchIndex;
use Ecrm\Search\SearchRebuilder;
use Ecrm\Storage\AtomicJsonStore;
use Ecrm\Support\Runtime;

$rebuilder = new SearchRebuilder(
    new AtomicJsonStore(Runtime::dataRoot()),
    new SearchIndex(Runtime::indexRoot())
);

$result = $rebuilder->rebuild();
echo json_encode(['status' => 'ok'] + $result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
