<?php

namespace Database\Factories;

use App\Models\Destination;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Destination>
 */
class DestinationFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->words(2, true).' bucket',
            'type' => 's3',
            'config' => [
                'region' => 'fra1',
                'bucket' => 'backups',
                'access_key' => 'AKIAEXAMPLE',
                'secret_key' => 'super-secret-key',
                'endpoint' => 'https://fra1.digitaloceanspaces.com',
                'prefix' => 'db',
                'use_path_style' => false,
            ],
        ];
    }

    /**
     * Indicate that the destination delivers files to a Telegram channel.
     */
    public function telegram(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => 'telegram',
            'config' => [
                'chat_id' => '-1001234567890',
                'bot_token' => '123456:test-bot-token',
            ],
        ]);
    }
}
