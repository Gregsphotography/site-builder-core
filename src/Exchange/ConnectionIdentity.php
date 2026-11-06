<?php

declare(strict_types=1);

namespace SiteBuilder\Core\Exchange;

use Illuminate\Database\Connection;
use RuntimeException;
use WeakMap;

final class ConnectionIdentity
{
    private static ?WeakMap $memory = null;

    public static function fingerprint(Connection $connection): string
    {
        if ($connection->getDriverName() === 'mysql') {
            $uuid = $connection->selectOne('SELECT @@server_uuid AS uuid')->uuid;
            $identity = ['mysql', $uuid, $connection->getDatabaseName()];
        } elseif ($connection->getDriverName() === 'sqlite') {
            $file = $connection->select('PRAGMA database_list')[0]->file;
            self::$memory ??= new WeakMap;
            $pdo = $connection->getPdo();
            self::$memory[$pdo] ??= bin2hex(random_bytes(16));
            $identity = ['sqlite', $file !== '' ? (realpath($file) ?: $file) : self::$memory[$pdo]];
        } else {
            throw new RuntimeException('Exchange currently supports SQLite and MySQL only.');
        }

        return hash('sha256', json_encode($identity, JSON_THROW_ON_ERROR));
    }
}
