<?php
declare(strict_types=1);

if (PHP_SAPI === 'cli-server') {
    $path = explode('?', $_SERVER['REQUEST_URI'], 2)[0];
    if (preg_match('~^/assets/[a-zA-Z0-9_-]+\.(?:css|js|woff2|svg|png|ico)$~D', $path) && is_file(__DIR__ . $path)
        && empty($_SERVER['HTTP_AUTHORIZATION']) && !isset($_GET['X-Amz-Algorithm'])) {
        return false;
    }
}
require dirname(__DIR__) . '/bootstrap.php';
