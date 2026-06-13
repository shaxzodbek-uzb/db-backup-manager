<?php

namespace Database\Factories;

use App\Models\BackupRun;
use App\Models\Connection;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BackupRun>
 */
class BackupRunFactory extends Factory
{
    protected $model = BackupRun::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'connection_id' => Connection::factory(),
            'trigger' => 'manual',
            'status' => 'success',
            'databases' => ['app'],
            'started_at' => now(),
            'finished_at' => now(),
            'duration_seconds' => 1,
            'total_bytes' => 1024,
        ];
    }
}
