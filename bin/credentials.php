<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
$password = bin2hex(random_bytes(16));
printf("Dashboard password: %s\n\nCopy these entries into config.php:\n", $password);
printf("    'admin_password_hash' => '%s',\n", password_hash($password, PASSWORD_DEFAULT));
printf("    'access_key' => '%s',\n", bin2hex(random_bytes(12)));
printf("    'secret_key' => '%s',\n", bin2hex(random_bytes(32)));
