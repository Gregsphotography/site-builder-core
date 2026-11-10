<?php

declare(strict_types=1);

namespace SiteBuilder\Core\Clipboard;

use Illuminate\Support\Facades\DB;
use SiteBuilder\Core\Content\CanonicalJson;
use SiteBuilder\Core\Exchange\ConnectionIdentity;

final class ClipboardNamespace
{
    public function current(): string
    {
        $connection = DB::connection();
        if ($connection->getDriverName() === 'pgsql') {
            $server = $connection->selectOne('SELECT system_identifier::text AS server_id, current_database() AS database_name, current_schema() AS schema_name FROM pg_control_system()');
            $database = (new CanonicalJson)->digest(['pgsql', $server->server_id, $server->database_name, $server->schema_name]);
        } else {
            $database = ConnectionIdentity::fingerprint($connection);
        }

        // Neither credentials, database coordinates nor APP_KEY leave the server.
        return (new CanonicalJson)->digest([
            'database' => $database,
            'application' => rtrim((string) config('app.url'), '/'),
        ]);
    }
}
