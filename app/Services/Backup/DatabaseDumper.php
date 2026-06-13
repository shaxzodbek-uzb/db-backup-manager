<?php

namespace App\Services\Backup;

use App\Models\Connection;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Spatie\DbDumper\Compressors\GzipCompressor;
use Spatie\DbDumper\Databases\PostgreSql;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * Produces a gzipped SQL dump of a single database using the system pg_dump.
 * The password is passed via PGPASSFILE (spatie/db-dumper), never on argv.
 */
class DatabaseDumper
{
    private ?string $pgDumpDirectory = null;

    /**
     * Dump one database to $targetPath (…/<db>.sql.gz). When $endpoint is given
     * (an open SSH tunnel) the dump connects through it instead of the host.
     *
     * @param  array{host: string, port: int}|null  $endpoint
     */
    public function dump(Connection $connection, string $database, string $targetPath, ?array $endpoint = null): void
    {
        if ($connection->driver !== 'pgsql') {
            // MySQL is deferred (PostgreSQL-first); fail loudly rather than
            // silently producing nothing.
            throw new RuntimeException("Dumping driver [{$connection->driver}] is not supported yet.");
        }

        File::ensureDirectoryExists(dirname($targetPath));

        PostgreSql::create()
            ->setHost($endpoint['host'] ?? $connection->host)
            ->setPort($endpoint['port'] ?? $connection->port)
            ->setDbName($database)
            ->setUserName($connection->username)
            ->setPassword((string) $connection->password)
            ->setDumpBinaryPath($this->pgDumpDirectory())
            ->setTimeout((int) config('backup.timeout', 3600))
            ->useCompressor(new GzipCompressor)
            ->dumpToFile($targetPath);
    }

    /**
     * Directory containing pg_dump. Honours an explicit config path, otherwise
     * picks the newest pg_dump found via the configured globs/search paths
     * (pg_dump must be >= the server's major version, so newest is safest),
     * finally falling back to PATH.
     */
    public function pgDumpDirectory(): string
    {
        if ($this->pgDumpDirectory !== null) {
            return $this->pgDumpDirectory;
        }

        $configured = config('backup.pg_dump_path');
        if (is_string($configured) && $configured !== '' && is_file(rtrim($configured, '/').'/pg_dump')) {
            return $this->pgDumpDirectory = rtrim($configured, '/');
        }

        $best = null;
        $bestVersion = '0';
        foreach ($this->candidateBinaries() as $binary) {
            $version = $this->binaryVersion($binary);
            if ($version !== null && version_compare($version, $bestVersion, '>')) {
                $best = $binary;
                $bestVersion = $version;
            }
        }

        if ($best !== null) {
            return $this->pgDumpDirectory = dirname($best);
        }

        $onPath = (new ExecutableFinder)->find('pg_dump');
        if ($onPath !== null) {
            return $this->pgDumpDirectory = dirname($onPath);
        }

        throw new RuntimeException(
            'pg_dump was not found. Install libpq/postgresql (e.g. `brew install libpq`) or set PG_DUMP_PATH.'
        );
    }

    /**
     * Every pg_dump binary discoverable via the configured version globs and the
     * static search paths.
     *
     * @return list<string>
     */
    private function candidateBinaries(): array
    {
        $binaries = [];

        /** @var list<string> $globs */
        $globs = config('backup.binary_globs', []);
        foreach ($globs as $glob) {
            foreach (glob(rtrim($glob, '/').'/bin/pg_dump') ?: [] as $binary) {
                $binaries[] = $binary;
            }
        }

        /** @var list<string> $dirs */
        $dirs = config('backup.binary_search_paths', []);
        foreach ($dirs as $dir) {
            $binaries[] = rtrim($dir, '/').'/pg_dump';
        }

        return array_values(array_unique(array_filter($binaries, 'is_executable')));
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
