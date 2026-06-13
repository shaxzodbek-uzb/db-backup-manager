<?php

namespace App\Concerns;

use App\Models\Connection;
use Illuminate\Support\Str;

trait RedactsSecrets
{
    /**
     * Strip a connection's secrets from a driver/exception message and collapse
     * it to a single, length-bounded line safe to surface or persist.
     */
    protected function redactSecrets(string $message, Connection $connection): string
    {
        $secrets = array_values(array_filter([
            (string) $connection->password,
            (string) $connection->ssh_password,
            (string) $connection->ssh_passphrase,
        ], fn (string $secret): bool => $secret !== ''));

        if ($secrets !== []) {
            $message = str_replace($secrets, '****', $message);
        }

        return Str::limit((string) preg_replace('/\s+/', ' ', trim($message)), 500);
    }
}
