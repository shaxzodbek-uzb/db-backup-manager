<?php

namespace App\Services\Backup;

use App\Models\Connection;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Spatie\DbDumper\Compressors\GzipCompressor;
use Spatie\DbDumper\Databases\MySql;
use Spatie\DbDumper\Databases\PostgreSql;
use Spatie\DbDumper\DbDumper;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * Produces a gzipped SQL dump of a single database using the system dump binary
 * (pg_dump or mysqldump). The password is passed via PGPASSFILE / a defaults
 * file (spatie/db-dumper), never on argv where `ps` would show it.
 */
class DatabaseDumper
{
    /**
     * Per-driver dump binary and how to find it.
     *
     * `prefer_newest` exists for PostgreSQL: pg_dump refuses to dump a server
     * newer than itself, so when several are installed the newest is the only
     * safe pick. mysqldump has no such rule, so the first one found is fine and
     * probing every candidate for its version would be wasted work.
     *
     * @var array<string, array{binary: string, class: class-string<DbDumper>, prefer_newest: bool, hint: string}>
     */
    private const DRIVERS = [
        'pgsql' => [
            'binary' => 'pg_dump',
            'class' => PostgreSql::class,
            'prefer_newest' => true,
            'hint' => 'Install libpq/postgresql (e.g. `brew install libpq`) or set PG_DUMP_PATH.',
        ],
        'mysql' => [
            'binary' => 'mysqldump',
            'class' => MySql::class,
            'prefer_newest' => false,
            'hint' => 'Install the MySQL or MariaDB client (e.g. `brew install mysql-client`) or set MYSQLDUMP_PATH.',
        ],
    ];

    /** Resolved binary directory, per driver. */
    private array $binaryDirectories = [];

    /**
     * Dump one database to $targetPath (…/<db>.sql.gz). When $endpoint is given
     * (an open SSH tunnel) the dump connects through it instead of the host.
     *
     * @param  array{host: string, port: int}|null  $endpoint
     */
    public function dump(Connection $connection, string $database, string $targetPath, ?array $endpoint = null): void
    {
        $driver = self::DRIVERS[$connection->driver] ?? null;

        if ($driver === null) {
            throw new RuntimeException(sprintf(
                'Dumping driver [%s] is not supported. Supported: %s.',
                $connection->driver,
                implode(', ', array_keys(self::DRIVERS)),
            ));
        }

        File::ensureDirectoryExists(dirname($targetPath));

        /** @var DbDumper $dumper */
        $dumper = $driver['class']::create();

        $dumper
            ->setHost($endpoint['host'] ?? $connection->host)
            ->setPort($endpoint['port'] ?? $connection->port)
            ->setDbName($database)
            ->setUserName($connection->username)
            ->setPassword((string) $connection->password)
            ->setDumpBinaryPath($this->binaryDirectory($connection->driver))
            ->setTimeout((int) config('backup.timeout', 3600))
            ->useCompressor(new GzipCompressor)
            ->dumpToFile($targetPath);
    }

    /**
     * Directory containing the dump binary for $driver.
     *
     * Honours an explicit config path first, then the configured globs and
     * search paths, then PATH.
     */
    public function binaryDirectory(string $driver): string
    {
        if (isset($this->binaryDirectories[$driver])) {
            return $this->binaryDirectories[$driver];
        }

        $spec = self::DRIVERS[$driver] ?? null;

        if ($spec === null) {
            throw new RuntimeException("No dump binary is configured for driver [{$driver}].");
        }

        $binary = $spec['binary'];

        $configured = config("backup.binaries.{$driver}.path");
        if (is_string($configured) && $configured !== '' && is_file(rtrim($configured, '/').'/'.$binary)) {
            return $this->binaryDirectories[$driver] = rtrim($configured, '/');
        }

        $candidates = $this->candidateBinaries($driver, $binary);

        if ($candidates !== []) {
            $chosen = $spec['prefer_newest'] ? $this->newest($candidates) : $candidates[0];

            if ($chosen !== null) {
                return $this->binaryDirectories[$driver] = dirname($chosen);
            }
        }

        $onPath = (new ExecutableFinder)->find($binary);
        if ($onPath !== null) {
            return $this->binaryDirectories[$driver] = dirname($onPath);
        }

        throw new RuntimeException("{$binary} was not found. {$spec['hint']}");
    }

    /**
     * Every dump binary for $driver discoverable via the configured globs and
     * static search paths.
     *
     * @return list<string>
     */
    private function candidateBinaries(string $driver, string $binary): array
    {
        $binaries = [];

        /** @var list<string> $globs */
        $globs = config("backup.binaries.{$driver}.globs", []);
        foreach ($globs as $glob) {
            foreach (glob(rtrim($glob, '/').'/bin/'.$binary) ?: [] as $found) {
                $binaries[] = $found;
            }
        }

        /** @var list<string> $dirs */
        $dirs = config("backup.binaries.{$driver}.search_paths", []);
        foreach ($dirs as $dir) {
            $binaries[] = rtrim($dir, '/').'/'.$binary;
        }

        return array_values(array_unique(array_filter($binaries, 'is_executable')));
    }

    /**
     * The highest-versioned binary from $candidates, or null when none reports
     * a version.
     *
     * @param  list<string>  $candidates
     */
    private function newest(array $candidates): ?string
    {
        $best = null;
        $bestVersion = '0';

        foreach ($candidates as $binary) {
            $version = $this->binaryVersion($binary);
            if ($version !== null && version_compare($version, $bestVersion, '>')) {
                $best = $binary;
                $bestVersion = $version;
            }
        }

        return $best;
    }

    private function binaryVersion(string $binary): ?string
    {
        $process = new Process([$binary, '--version']);
        $process->run();

        if (! $process->isSuccessful()) {
            return null;
        }

        return preg_match('/(\d+(?:\.\d+)*)/', $process->getOutput(), $matches) === 1 ? $matches[1] : null;
    }
}
