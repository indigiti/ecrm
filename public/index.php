<?php
declare(strict_types=1);

define('ECRM_PUBLIC_ROOT', __DIR__);

$explicit = getenv('ECRM_PRIVATE_ROOT');
$appRoot = dirname(__DIR__, 2);
$cloudwaysPrivate = $appRoot . '/private_html/ecrm';
$repositoryRoot = dirname(__DIR__);

if (is_string($explicit) && $explicit !== '') {
    $privateRoot = rtrim($explicit, '/');
} elseif (is_file($cloudwaysPrivate . '/app/bootstrap.php')) {
    $privateRoot = $cloudwaysPrivate;
} else {
    $privateRoot = $repositoryRoot;
}

define('ECRM_PRIVATE_ROOT', $privateRoot);

require ECRM_PRIVATE_ROOT . '/app/bootstrap.php';

\Ecrm\Http\Application::run();
