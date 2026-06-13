<?php

namespace Database\Factories;

use App\Models\Connection;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Connection>
 */
class ConnectionFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->words(2, true).' db',
            'driver' => 'mysql',
            'host' => '127.0.0.1',
            'port' => 3306,
            'username' => 'root',
            'password' => 'secret',
            'ssh_enabled' => false,
        ];
    }

    /**
     * Indicate that the connection targets a PostgreSQL server.
     */
    public function postgres(): static
    {
        return $this->state(fn (array $attributes) => [
            'driver' => 'pgsql',
            'port' => 5432,
            'username' => 'postgres',
        ]);
    }

    /**
     * Indicate that the connection reaches the database through an SSH tunnel.
     */
    public function withSshTunnel(): static
    {
        return $this->state(fn (array $attributes) => [
            'ssh_enabled' => true,
            'ssh_host' => 'bastion.example.com',
            'ssh_port' => 22,
            'ssh_user' => 'tunnel',
            'ssh_auth' => 'key',
            'ssh_private_key' => "-----BEGIN OPENSSH PRIVATE KEY-----\nfake-key-material\n-----END OPENSSH PRIVATE KEY-----",
        ]);
    }
}
