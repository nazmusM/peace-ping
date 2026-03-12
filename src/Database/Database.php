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
                self::$instance = new mysqli(
                    $config['host'],
                    $config['user'],
                    $config['password'],
                    $config['database'],
                    isset($config['port']) ? (int) $config['port'] : 3306
                );
                self::$instance->set_charset('utf8mb4');
            } catch (mysqli_sql_exception $exception) {
                throw new mysqli_sql_exception('Database connection failed.');
            }
        }

        return self::$instance;
    }
}
