<?php

namespace App\Support;

class DatabaseUrlParser
{
    /**
     * Parse a database connection URL (e.g. postgres://user:pass@host:5432/db)
     * into the connection's discrete fields. Returns null when the URL is
     * unusable or its scheme is not a supported driver.
     *
     * @return array{driver: string, host: string, port: int, username: string, password: string}|null
     */
    public static function parse(string $url): ?array
    {
        $url = trim($url);
        if ($url === '') {
            return null;
        }

        $parts = parse_url($url);
        if ($parts === false || ! isset($parts['host'], $parts['scheme'])) {
            return null;
        }

        $driver = match (strtolower($parts['scheme'])) {
            'postgres', 'postgresql', 'pgsql' => 'pgsql',
            'mysql', 'mariadb' => 'mysql',
            default => null,
        };

        if ($driver === null) {
            return null;
        }

        return [
            'driver' => $driver,
            'host' => $parts['host'],
            'port' => $parts['port'] ?? ($driver === 'pgsql' ? 5432 : 3306),
            'username' => isset($parts['user']) ? rawurldecode($parts['user']) : '',
            'password' => isset($parts['pass']) ? rawurldecode($parts['pass']) : '',
        ];
    }
}
