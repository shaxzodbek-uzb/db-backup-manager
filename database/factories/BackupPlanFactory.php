<?php

namespace Database\Factories;

use App\Models\BackupPlan;
use App\Models\Connection;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BackupPlan>
 */
class BackupPlanFactory extends Factory
{
    protected $model = BackupPlan::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->words(2, true).' plan',
            'connection_id' => Connection::factory()->postgres(),
            'selection' => 'all',
            'cron' => '0 2 * * *',
            'timezone' => 'UTC',
            'enabled' => true,
        ];
    }

    /**
     * A plan whose cron is due on every evaluation.
     */
    public function dueEveryMinute(): static
    {
        return $this->state(fn (array $attributes) => ['cron' => '* * * * *']);
    }
}
