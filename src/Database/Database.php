<?php

namespace App\Database;

use mysqli;
use mysqli_sql_exception;

class Database
{
    private static ?mysqli $instance = null;

    public static function getConnection(array $config): mysqli
    {
        if (self::$instance === null) {
            mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

            try {
                $host = (string) ($config['host'] ?? '127.0.0.1');
                $user = (string) ($config['user'] ?? '');
                $password = (string) ($config['password'] ?? '');
                $database = (string) ($config['database'] ?? '');
                $port = isset($config['port']) && $config['port'] !== '' ? (int) $config['port'] : 3306;
                $socket = isset($config['socket']) && $config['socket'] !== '' ? (string) $config['socket'] : null;

                self::$instance = mysqli_init();
                if (self::$instance === false) {
                    throw new mysqli_sql_exception('Database initialization failed.');
                }

                self::$instance->real_connect(
                    $host,
                    $user,
                    $password,
                    $database,
                    $port,
                    $socket
                );
                self::$instance->set_charset('utf8mb4');
            } catch (mysqli_sql_exception $exception) {
                throw new mysqli_sql_exception('Database connection failed.');
            }
        }

        return self::$instance;
    }
}
