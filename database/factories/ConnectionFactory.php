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
}
