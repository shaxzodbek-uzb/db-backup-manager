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
        return $this->redactStrings($message, [
            (string) $connection->password,
            (string) $connection->ssh_password,
            (string) $connection->ssh_passphrase,
            (string) $connection->ssh_private_key,
        ]);
    }

    /**
     * Strip the given secret strings from a message and collapse it to a single,
     * length-bounded line.
     *
     * @param  list<string>  $secrets
     */
    protected function redactStrings(string $message, array $secrets): string
    {
        $secrets = array_values(array_filter($secrets, fn (string $secret): bool => $secret !== ''));

        if ($secrets !== []) {
            $message = str_replace($secrets, '****', $message);
        }

        return Str::limit((string) preg_replace('/\s+/', ' ', trim($message)), 500);
    }
}
