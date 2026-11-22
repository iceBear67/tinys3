<?php
declare(strict_types=1);

final class S3Error extends RuntimeException
{
    public function __construct(public readonly string $s3code, string $message, public readonly int $status)
    {
        parent::__construct($message);
    }
}

require __DIR__ . '/src/Download.php';
require __DIR__ . '/src/Credentials.php';
require __DIR__ . '/src/Auth.php';
require __DIR__ . '/src/Store.php';

ini_set('display_errors', '0');
date_default_timezone_set('UTC');
$requestId = bin2hex(random_bytes(12));
header('x-amz-request-id: ' . $requestId);
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header('Cache-Control: private, no-store');
$path = explode('?', $_SERVER['REQUEST_URI'], 2)[0];
$dashboard = in_array($path, ['/admin', '/admin/'], true)
    && empty($_SERVER['HTTP_AUTHORIZATION']) && empty($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])
    && !isset($_GET['X-Amz-Algorithm']);
if (!$dashboard) {
    ini_set('default_charset', '');
    ini_set('zlib.output_compression', '0');
    // Clients can store active content such as HTML and SVG on the API origin.
    header("Content-Security-Policy: sandbox; default-src 'none'; frame-ancestors 'none'");
}
try {
    $config = require __DIR__ . '/config.php';
    foreach (['dsn', 'db_user', 'storage_dir', 'admin_password_hash', 'access_key', 'secret_key', 'region'] as $field) {
        if (!is_string($config[$field] ?? null) || $config[$field] === '') {
            throw new RuntimeException('Missing configuration field ' . $field);
        }
    }
    if (!password_get_info($config['admin_password_hash'])['algo']) {
        throw new RuntimeException('Invalid administrator password hash.');
    }
    $storage = realpath($config['storage_dir']);
    $webRoot = realpath($_SERVER['DOCUMENT_ROOT']);
    if (!$storage || !is_dir($storage) || !$webRoot || str_starts_with($storage . '/', $webRoot . '/') || str_starts_with(__DIR__ . '/', $webRoot . '/')) {
        throw new RuntimeException('Configuration and object storage require existing directories outside the document root.');
    }
    $db = new PDO($config['dsn'], $config['db_user'], $config['db_password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $db->exec("SET time_zone = '+00:00'");
    $credentials = new Credentials($db, $config['access_key']);
    $request = $dashboard ? null : verifyS3($config, $credentials);
    $store = new Store($db, $storage);
    if ($request === null) {
        require __DIR__ . '/src/Dashboard.php';
        serveDashboard($store, $config, $credentials);
    } elseif (isset($request->query['uploads']) || isset($request->query['uploadId'])) {
        require __DIR__ . '/src/Multipart.php';
        (new MultipartApi($store, $db, $storage, $request))->serve();
    } else {
        require __DIR__ . '/src/S3.php';
        (new S3Api($store, $request))->serve($config);
    }
} catch (Throwable $error) {
    if ($error instanceof S3Error) {
        $status = $error->status;
        $code = $error->s3code;
        $message = $error->getMessage();
    } else {
        error_log('TinyS3 ' . $requestId . ' ' . $error->getMessage());
        $status = 500;
        $code = 'InternalError';
        $message = 'Server error. Check the PHP error log using the request ID.';
    }
    if (headers_sent()) {
        return;
    }
    http_response_code($status);
    header_remove('Content-Length');
    header('Content-Type: application/xml; charset=utf-8');
    if ($_SERVER['REQUEST_METHOD'] !== 'HEAD') {
        echo '<?xml version="1.0" encoding="UTF-8"?><Error><Code>', $code, '</Code><Message>',
            htmlspecialchars($message, ENT_XML1 | ENT_SUBSTITUTE, 'UTF-8'), '</Message><RequestId>', $requestId, '</RequestId></Error>';
    }
}
