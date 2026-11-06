<?php

return ['default' => env('DB_CONNECTION', 'sqlite'), 'migrations' => ['table' => 'migrations'], 'connections' => [
    'mysql' => ['driver' => 'mysql', 'host' => env('DB_HOST', '127.0.0.1'), 'port' => env('DB_PORT', '3306'), 'unix_socket' => env('DB_SOCKET', ''), 'database' => env('DB_DATABASE'), 'username' => env('DB_USERNAME'), 'password' => env('DB_PASSWORD'), 'charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci', 'prefix' => '', 'strict' => true],
    'sqlite' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true],
]];
