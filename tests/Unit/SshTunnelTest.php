<?php

namespace Tests\Unit;

use App\Models\Connection;
use App\Services\Ssh\SshTunnel;
use RuntimeException;
use Tests\TestCase;

class SshTunnelTest extends TestCase
{
    public function test_command_builds_a_local_forward_argument_vector(): void
    {
        $connection = Connection::factory()->withSshTunnel()->make([
            'host' => 'db.internal',
            'port' => 5432,
            'ssh_host' => 'bastion.example.com',
            'ssh_port' => 2222,
            'ssh_user' => 'jump',
        ]);

        $command = app(SshTunnel::class)->command($connection, 54321, '/tmp/key');

        $this->assertSame('ssh', $command[0]);
        $this->assertContains('-N', $command);
        $this->assertContains('127.0.0.1:54321:db.internal:5432', $command);
        $this->assertContains('jump@bastion.example.com', $command);
        $this->assertContains('/tmp/key', $command);
        $this->assertContains('2222', $command);
        $this->assertContains('BatchMode=yes', $command);
        $this->assertContains('StrictHostKeyChecking=accept-new', $command);
    }

    public function test_open_rejects_password_authentication(): void
    {
        $connection = Connection::factory()->withSshTunnel()->make(['ssh_auth' => 'password']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('key authentication');

        app(SshTunnel::class)->open($connection);
    }

    public function test_open_requires_a_private_key(): void
    {
        $connection = Connection::factory()->withSshTunnel()->make([
            'ssh_auth' => 'key',
            'ssh_private_key' => '',
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('private key');

        app(SshTunnel::class)->open($connection);
    }
}
