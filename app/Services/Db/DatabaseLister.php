<?php

namespace App\Services\Db;

use App\Models\Connection;

class DatabaseLister
{
    /**
     * MySQL/MariaDB system schemas excluded from backups.
     *
     * @var list<string>
     */
    private const MYSQL_SYSTEM = ['information_schema', 'performance_schema', 'mysql', 'sys'];

    public function __construct(private DynamicConnection $dynamic) {}

    /**
     * List the user databases on the server with their on-disk size.
     *
     * @return array<int, array{name: string, size_bytes: int}>
     */
    public function list(Connection $connection): array
    {
        try {
            return $connection->driver === 'pgsql'
                ? $this->postgres($connection)
                : $this->mysql($connection);
        } finally {
            $this->dynamic->forget();
        }
    }

    /**
     * @return array<int, array{name: string, size_bytes: int}>
     */
    private function mysql(Connection $connection): array
    {
        $db = $this->dynamic->make($connection);

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
     * @return array<int, array{name: string, size_bytes: int}>
     */
    private function postgres(Connection $connection): array
    {
        $db = $this->dynamic->make($connection, 'postgres');

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
