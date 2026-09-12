<?php

declare(strict_types=1);
// Receives $omekaVendor from cli/bootstrap.php.
$omekaRoot = dirname($omekaVendor, 2); // …/vendor/autoload.php → …
$dbIni = getenv('IWAC_OMEKA_DB_INI') ?: $omekaRoot . '/config/database.ini';
if (!is_readable($dbIni)) {
    fwrite(STDERR, "ERROR: Omeka database.ini not readable at {$dbIni}. Set IWAC_OMEKA_DB_INI.\n");
    exit(2);
}

$ini = parse_ini_file($dbIni) ?: [];
$params = [
    'driver'   => 'pdo_mysql',
    'charset'  => 'utf8mb4',
    'dbname'   => (string) ($ini['dbname'] ?? ''),
    'user'     => (string) ($ini['user'] ?? ''),
    'password' => (string) ($ini['password'] ?? ''),
];
if (!empty($ini['unix_socket'])) {
    $params['unix_socket'] = (string) $ini['unix_socket'];
} else {
    $params['host'] = (string) ($ini['host'] ?? 'localhost');
    if (!empty($ini['port'])) {
        $params['port'] = (int) $ini['port'];
    }
}
return \Doctrine\DBAL\DriverManager::getConnection($params);
