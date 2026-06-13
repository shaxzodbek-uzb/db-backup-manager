<?php

namespace App\Services\Db;

use App\Concerns\RedactsSecrets;
use App\Models\Connection;
use App\Services\TestResult;
use Throwable;

class ConnectionTester
{
    use RedactsSecrets;

    public function __construct(private DynamicConnection $dynamic) {}

    /**
     * Attempt a live connection and a trivial query, never throwing.
     */
    public function test(Connection $connection): TestResult
    {
        $start = microtime(true);

        try {
            $db = $this->dynamic->make($connection);
            $db->getPdo();
            $db->select('select 1');

            return new TestResult(true, null, $this->elapsedMs($start));
        } catch (Throwable $e) {
            return new TestResult(false, $this->redactSecrets($e->getMessage(), $connection), $this->elapsedMs($start));
        } finally {
            $this->dynamic->forget();
        }
    }

    private function elapsedMs(float $start): int
    {
        return (int) round((microtime(true) - $start) * 1000);
    }
}
