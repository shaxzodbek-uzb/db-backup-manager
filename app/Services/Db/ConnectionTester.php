<?php

namespace App\Services\Db;

use App\Concerns\RedactsSecrets;
use App\Models\Connection;
use App\Services\Ssh\SshTunnel;
use App\Services\TestResult;
use Throwable;

class ConnectionTester
{
    use RedactsSecrets;

    public function __construct(
        private DynamicConnection $dynamic,
        private SshTunnel $tunnel,
    ) {}

    /**
     * Attempt a live connection and a trivial query, never throwing.
     *
     * When the connection uses an SSH tunnel the probe runs through a freshly
     * opened local forward, which is torn down again in every outcome.
     */
    public function test(Connection $connection): TestResult
    {
        $start = microtime(true);
        $tunnel = $connection->ssh_enabled ? $this->tunnel : null;

        try {
            $endpoint = $tunnel?->open($connection);

            $db = $this->dynamic->make($connection, null, $endpoint['host'] ?? null, $endpoint['port'] ?? null);
            $db->getPdo();
            $db->select('select 1');

            return new TestResult(true, null, $this->elapsedMs($start));
        } catch (Throwable $e) {
            return new TestResult(false, $this->redactSecrets($e->getMessage(), $connection), $this->elapsedMs($start));
        } finally {
            $tunnel?->close();
            $this->dynamic->forget();
        }
    }

    private function elapsedMs(float $start): int
    {
        return (int) round((microtime(true) - $start) * 1000);
    }
}
