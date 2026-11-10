<?php

declare(strict_types=1);

namespace SiteBuilder\Core\Tests;

use LogicException;
use Orchestra\Testbench\TestCase as Orchestra;
use SiteBuilder\Core\SiteBuilderServiceProvider;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [SiteBuilderServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $driver = getenv('DB_CONNECTION') ?: 'sqlite';
        if (! in_array($driver, ['sqlite', 'mysql', 'pgsql'], true)) {
            throw new LogicException('Foundation tests support isolated SQLite, MySQL or PostgreSQL only.');
        }
        $app['config']->set('database.default', $driver);
        if ($driver === 'sqlite') {
            $app['config']->set('database.connections.sqlite.database', ':memory:');
            $app['config']->set('database.connections.sqlite.foreign_key_constraints', true);
        } elseif ($driver === 'pgsql') {
            $database = getenv('DB_DATABASE') ?: '';
            if (! str_starts_with($database, 'site_builder_') || ! str_ends_with($database, '_test')) {
                throw new LogicException('PostgreSQL tests require a dedicated site_builder_*_test database.');
            }
            $app['config']->set('database.connections.pgsql', [
                'driver' => 'pgsql', 'host' => getenv('DB_HOST') ?: '127.0.0.1', 'port' => getenv('DB_PORT') ?: '5432',
                'database' => $database, 'username' => getenv('DB_USERNAME') ?: 'postgres', 'password' => getenv('DB_PASSWORD') ?: '',
                'charset' => 'utf8', 'prefix' => '', 'search_path' => 'public', 'sslmode' => 'prefer',
            ]);
        } else {
            $database = getenv('DB_DATABASE') ?: '';
            if (! str_starts_with($database, 'site_builder_') || ! str_ends_with($database, '_test')) {
                throw new LogicException('MySQL tests require a dedicated site_builder_*_test database.');
            }
            $app['config']->set('database.connections.mysql', [
                'driver' => 'mysql', 'host' => getenv('DB_HOST') ?: '127.0.0.1',
                'port' => getenv('DB_PORT') ?: '3306', 'unix_socket' => getenv('DB_SOCKET') ?: '',
                'database' => $database, 'username' => getenv('DB_USERNAME') ?: 'root', 'password' => getenv('DB_PASSWORD') ?: '',
                'charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci', 'prefix' => '', 'strict' => true,
            ]);
        }
    }
}
