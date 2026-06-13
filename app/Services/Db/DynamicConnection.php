<?php

namespace App\Services\Db;

use App\Models\Connection;
use Illuminate\Database\Connection as DbConnection;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;

/**
 * Builds a runtime Laravel database connection from a Connection record.
 *
 * The optional $host/$port overrides exist so an SSH tunnel can later point the
 * dumper at 127.0.0.1:<localPort> without any other code changing.
 */
class DynamicConnection
{
    private const NAME = 'dynamic_backup';

    public function make(Connection $connection, ?string $database = null, ?string $host = null, ?int $port = null): DbConnection
    {
        $config = $this->config(
            $connection,
            $database,
            $host ?? $connection->host,
            $port ?? $connection->port,
        );

        Config::set('database.connections.'.self::NAME, $config);

        // Drop any stale PDO bound to a previous record before reconnecting.
        DB::purge(self::NAME);

        return DB::connection(self::NAME);
    }

    /**
     * Tear down the runtime connection and remove its config.
     */
    public function forget(): void
    {
        DB::purge(self::NAME);
        Config::offsetUnset('database.connections.'.self::NAME);
    }

    /**
     * @return array<string, mixed>
     */
    private function config(Connection $connection, ?string $database, string $host, int $port): array
    {
        return match ($connection->driver) {
            'pgsql' => [
                'driver' => 'pgsql',
                'host' => $host,
                'port' => $port,
                // Postgres always connects to a concrete database; the
                // maintenance "postgres" db is a safe default for probing.
                'database' => $database ?? 'postgres',
                'username' => $connection->username,
                'password' => (string) $connection->password,
                'charset' => 'utf8',
                'search_path' => 'public',
                'sslmode' => $connection->ssl_mode ?: 'prefer',
            ],
            default => [
                'driver' => 'mysql',
                'host' => $host,
                'port' => $port,
                'database' => $database ?? '',
                'username' => $connection->username,
                'password' => (string) $connection->password,
                'charset' => 'utf8mb4',
                'collation' => 'utf8mb4_unicode_ci',
            ],
        };
    }
}
