<?php

namespace Tests\Feature;

use App\Jobs\RunBackupJob;
use App\Models\BackupArtifact;
use App\Models\BackupPlan;
use App\Models\BackupRun;
use App\Models\Connection;
use App\Services\Backup\RetentionManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class BackupPlanTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_every_minute_plan_is_due(): void
    {
        $plan = BackupPlan::factory()->dueEveryMinute()->make();

        $this->assertTrue($plan->isDue(Carbon::parse('2026-06-13 12:30:00')));
    }

    public function test_a_specific_time_plan_is_not_due_at_other_times(): void
    {
        $plan = BackupPlan::factory()->make(['cron' => '0 0 1 1 *']); // Jan 1, 00:00

        $this->assertFalse($plan->isDue(Carbon::parse('2026-06-13 12:30:00')));
    }

    public function test_dispatch_due_only_dispatches_enabled_due_plans(): void
    {
        Queue::fake();
        Carbon::setTestNow('2026-06-13 12:30:00');

        $due = BackupPlan::factory()->dueEveryMinute()->create(['enabled' => true]);
        BackupPlan::factory()->create(['cron' => '0 0 1 1 *', 'enabled' => true]); // not due now
        BackupPlan::factory()->dueEveryMinute()->create(['enabled' => false]);     // due but disabled

        $this->artisan('backups:dispatch-due')->assertExitCode(0);

        Queue::assertPushed(RunBackupJob::class, 1);
        Queue::assertPushed(
            RunBackupJob::class,
            fn (RunBackupJob $job): bool => $job->backupPlanId === $due->id
                && $job->trigger === 'scheduled'
                && $job->connectionId === $due->connection_id,
        );
    }

    public function test_dispatch_due_updates_last_and_next_run(): void
    {
        Queue::fake();
        Carbon::setTestNow('2026-06-13 12:30:00');
        $plan = BackupPlan::factory()->dueEveryMinute()->create();

        $this->artisan('backups:dispatch-due')->assertExitCode(0);

        $plan->refresh();
        $this->assertNotNull($plan->last_run_at);
        $this->assertNotNull($plan->next_run_at);
    }

    public function test_command_creates_a_plan(): void
    {
        $connection = Connection::factory()->postgres()->create();

        $this->artisan('backups:plan', ['connection' => $connection->id, '--cron' => '* * * * *'])
            ->assertExitCode(0);

        $this->assertDatabaseHas('backup_plans', [
            'connection_id' => $connection->id,
            'cron' => '* * * * *',
            'selection' => 'all',
        ]);
    }

    public function test_retention_prunes_old_artifact_files_keeping_the_newest(): void
    {
        $plan = BackupPlan::factory()->create(['retention_copies' => 1]);

        $older = $this->seededRun($plan, 'backups/test/older.sql.gz');
        $newer = $this->seededRun($plan, 'backups/test/newer.sql.gz');

        app(RetentionManager::class)->prune($plan);

        $this->assertFalse(File::exists(storage_path('app/backups/test/older.sql.gz')));
        $this->assertTrue(File::exists(storage_path('app/backups/test/newer.sql.gz')));
        $this->assertSoftDeleted($older->artifacts()->withTrashed()->first());
        $this->assertNotSoftDeleted($newer->artifacts()->first());
    }

    private function seededRun(BackupPlan $plan, string $path): BackupRun
    {
        $run = BackupRun::factory()->create([
            'connection_id' => $plan->connection_id,
            'backup_plan_id' => $plan->id,
            'status' => 'success',
        ]);

        File::ensureDirectoryExists(dirname(storage_path('app/'.$path)));
        File::put(storage_path('app/'.$path), 'dump');

        BackupArtifact::factory()->for($run, 'run')->create(['path' => $path]);

        return $run;
    }
}
