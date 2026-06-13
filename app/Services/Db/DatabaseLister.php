<?php

namespace App\Services\Db;

use App\Models\Connection;
use App\Services\Ssh\SshTunnel;

class DatabaseLister
{
    /**
     * MySQL/MariaDB system schemas excluded from backups.
     *
     * @var list<string>
     */
    private const MYSQL_SYSTEM = ['information_schema', 'performance_schema', 'mysql', 'sys'];

    public function __construct(
        private DynamicConnection $dynamic,
        private SshTunnel $tunnel,
    ) {}

    /**
     * List the user databases on the server with their on-disk size, routing
     * through an SSH tunnel when the connection requires one.
     *
     * @return array<int, array{name: string, size_bytes: int}>
     */
    public function list(Connection $connection): array
    {
        $tunnel = $connection->ssh_enabled ? $this->tunnel : null;

        try {
            $endpoint = $tunnel?->open($connection);

            return $connection->driver === 'pgsql'
                ? $this->postgres($connection, $endpoint)
                : $this->mysql($connection, $endpoint);
        } finally {
            $tunnel?->close();
            $this->dynamic->forget();
        }
    }

    /**
     * @param  array{host: string, port: int}|null  $endpoint
     * @return array<int, array{name: string, size_bytes: int}>
     */
    private function mysql(Connection $connection, ?array $endpoint): array
    {
        $db = $this->dynamic->make($connection, null, $endpoint['host'] ?? null, $endpoint['port'] ?? null);

        $names = array_map(
            fn (object $row): string => (string) array_values((array) $row)[0],
            $db->select('SHOW DATABASES'),
        );
        $names = array_values(array_filter(
            $names,
            fn (string $name): bool => ! in_array($name, self::MYSQL_SYSTEM, true),
        ));

        $sizes = [];
        $rows = $db->select(
            'SELECT table_schema AS db, SUM(data_length + index_length) AS bytes '.
            'FROM information_schema.tables GROUP BY table_schema'
        );
        foreach ($rows as $row) {
            $sizes[$row->db] = (int) $row->bytes;
        }

        return array_map(
            fn (string $name): array => ['name' => $name, 'size_bytes' => $sizes[$name] ?? 0],
            $names,
        );
    }

    /**
     * @param  array{host: string, port: int}|null  $endpoint
     * @return array<int, array{name: string, size_bytes: int}>
     */
    private function postgres(Connection $connection, ?array $endpoint): array
    {
        $db = $this->dynamic->make($connection, 'postgres', $endpoint['host'] ?? null, $endpoint['port'] ?? null);

        $rows = $db->select(
            'SELECT datname AS name, pg_database_size(datname) AS bytes FROM pg_database '.
            'WHERE NOT datistemplate AND datallowconn ORDER BY datname'
        );

        return array_map(
            fn (object $row): array => ['name' => (string) $row->name, 'size_bytes' => (int) $row->bytes],
            $rows,
        );
    }
}
