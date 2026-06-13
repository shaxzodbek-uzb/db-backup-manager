<?php

namespace Database\Factories;

use App\Models\BackupArtifact;
use App\Models\BackupRun;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BackupArtifact>
 */
class BackupArtifactFactory extends Factory
{
    protected $model = BackupArtifact::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'backup_run_id' => BackupRun::factory(),
            'database' => 'app',
            'disk' => 'local',
            'path' => 'backups/1/1/app.sql.gz',
            'size_bytes' => 1024,
            'compressed' => true,
        ];
    }
}
