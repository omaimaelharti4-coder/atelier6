<?php

ini_set('display_errors', '0');
error_reporting(E_ALL & ~E_DEPRECATED);

function getDatabase(): PDO
{
    $host     = getenv('DB_HOST');
    $port     = getenv('DB_PORT');
    $name     = getenv('DB_NAME');
    $user     = getenv('DB_USER');
    $password = getenv('DB_PASSWORD');

    // 1) On cherche ca.pem à plusieurs endroits possibles sur Vercel
    $candidats = [
        dirname(__DIR__) . '/certs/ca.pem',
        __DIR__ . '/../certs/ca.pem',
        getcwd() . '/certs/ca.pem',
        '/var/task/user/certs/ca.pem',s
    ];
    $caPath = null;
    foreach ($candidats as $c) {
        if (is_readable($c) && filesize($c) > 500) {
            $caPath = $c;
            break;
        }
    }
    if ($caPath === null) {
        throw new RuntimeException('ca.pem introuvable. Cherche dans : ' . implode(' | ', $candidats));
    }

    $dsn = "mysql:host=$host;port=$port;dbname=$name;charset=utf8mb4";

    $newApi    = class_exists('Pdo\Mysql');
    $sslCa     = $newApi ? \Pdo\Mysql::ATTR_SSL_CA : PDO::MYSQL_ATTR_SSL_CA;
    $sslVerify = $newApi ? \Pdo\Mysql::ATTR_SSL_VERIFY_SERVER_CERT : PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT;

    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
        $sslCa                       => $caPath,
        $sslVerify                   => true,
    ];

    return new PDO($dsn, $user, $password, $options);
}
