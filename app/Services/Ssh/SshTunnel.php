<?php

namespace App\Services\Ssh;

use App\Models\Connection;
use RuntimeException;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Opens an SSH local-forward tunnel (via the system `ssh` binary) so a database
 * behind a bastion can be reached at 127.0.0.1:<localPort>.
 *
 * v1 supports key authentication only: the decrypted private key is written to a
 * private (0600) temp file for the lifetime of the tunnel and removed on close().
 */
class SshTunnel
{
    /** Seconds to wait for the forwarded port to start accepting connections. */
    private const READY_TIMEOUT = 15;

    private ?Process $process = null;

    private ?string $keyFile = null;

    /**
     * Open the tunnel and return the local endpoint to connect through.
     * The caller MUST call close() in a finally block.
     *
     * @return array{host: string, port: int}
     */
    public function open(Connection $connection): array
    {
        // Never leak a previous tunnel if this instance is reused.
        $this->close();

        if (! $connection->ssh_enabled) {
            throw new RuntimeException('SSH tunnel requested for a connection without SSH enabled.');
        }

        if (($connection->ssh_auth ?? 'key') !== 'key') {
            // The system ssh client cannot take a password non-interactively
            // without extra tooling; v1 is key-auth only.
            throw new RuntimeException('Only SSH key authentication is supported.');
        }

        if (blank($connection->ssh_private_key)) {
            throw new RuntimeException('SSH private key is not set on this connection.');
        }

        $localPort = $this->freeLocalPort();
        $this->keyFile = $this->writeKeyFile((string) $connection->ssh_private_key);

        $this->process = new Process($this->command($connection, $localPort, $this->keyFile));
        $this->process->setTimeout(null);
        $this->process->start();

        try {
            $this->waitUntilReady($localPort);
        } catch (Throwable $e) {
            $this->close();

            throw $e;
        }

        return ['host' => '127.0.0.1', 'port' => $localPort];
    }

    /**
     * Tear down the tunnel process and remove the temporary key file. Idempotent.
     */
    public function close(): void
    {
        $this->process?->stop(2);
        $this->process = null;

        if ($this->keyFile !== null && is_file($this->keyFile)) {
            @unlink($this->keyFile);
        }
        $this->keyFile = null;
    }

    /**
     * The `ssh` argument vector — public so it can be asserted in tests without
     * actually spawning ssh.
     *
     * @return list<string>
     */
    public function command(Connection $connection, int $localPort, string $keyFile): array
    {
        return [
            'ssh',
            '-i', $keyFile,
            '-p', (string) ($connection->ssh_port ?: 22),
            '-N', // forward only, run no remote command
            '-L', "127.0.0.1:{$localPort}:{$connection->host}:{$connection->port}",
            '-o', 'StrictHostKeyChecking=accept-new',
            '-o', 'ExitOnForwardFailure=yes',
            '-o', 'BatchMode=yes', // fail fast instead of prompting for input
            '-o', 'ConnectTimeout=10',
            '-o', 'ServerAliveInterval=10',
            '-o', 'ServerAliveCountMax=3',
            "{$connection->ssh_user}@{$connection->ssh_host}",
        ];
    }

    private function waitUntilReady(int $localPort): void
    {
        $deadline = microtime(true) + self::READY_TIMEOUT;

        while (microtime(true) < $deadline) {
            if (! $this->process->isRunning()) {
                throw new RuntimeException($this->failureReason('SSH exited before the tunnel was ready.'));
            }

            $socket = @fsockopen('127.0.0.1', $localPort, $errno, $errstr, 0.5);
            if ($socket !== false) {
                fclose($socket);

                return;
            }

            usleep(200_000);
        }

        throw new RuntimeException($this->failureReason('Timed out opening the SSH tunnel.'));
    }

    private function failureReason(string $fallback): string
    {
        $stderr = trim((string) $this->process?->getErrorOutput());

        return $stderr !== '' ? $stderr : $fallback;
    }

    private function freeLocalPort(): int
    {
        $server = @stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        if ($server === false) {
            throw new RuntimeException('Could not allocate a local port for the SSH tunnel.');
        }

        $name = (string) stream_socket_get_name($server, false);
        fclose($server);

        return (int) substr($name, strrpos($name, ':') + 1);
    }

    private function writeKeyFile(string $key): string
    {
        $path = tempnam(sys_get_temp_dir(), 'ssh_key_');
        if ($path === false) {
            throw new RuntimeException('Could not create a temporary SSH key file.');
        }

        chmod($path, 0600);
        file_put_contents($path, rtrim($key, "\n")."\n"); // ssh requires a trailing newline

        return $path;
    }
}
