<?php

namespace Tests\Feature;

use App\Jobs\RunBackupJob;
use App\Models\BackupArtifact;
use App\Models\BackupRun;
use App\Models\Connection;
use App\Services\Backup\DatabaseDumper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\TestCase;

class BackupTest extends TestCase
{
    use RefreshDatabase;

    public function test_dumper_rejects_unsupported_drivers(): void
    {
        // mysql used to land here; it is supported now, so this asserts a driver
        // that genuinely has no dumper rather than re-asserting a removed limit.
        $connection = Connection::factory()->make(['driver' => 'sqlite']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('is not supported');

        app(DatabaseDumper::class)->dump($connection, 'app', sys_get_temp_dir().'/should-not-exist.sql.gz');
    }

    public function test_backup_run_has_many_artifacts(): void
    {
        $run = BackupRun::factory()
            ->has(BackupArtifact::factory()->count(2), 'artifacts')
            ->create();

        $this->assertCount(2, $run->artifacts);
        $this->assertSame($run->id, $run->artifacts->first()->run->id);
    }

    public function test_job_records_a_failed_run_for_an_unreachable_server(): void
    {
        $connection = Connection::factory()->postgres()->create(['host' => '127.0.0.1', 'port' => 1]);

        dispatch_sync(new RunBackupJob($connection->id, ['app']));

        $run = BackupRun::firstOrFail();
        $this->assertSame($connection->id, $run->connection_id);
        $this->assertSame('failed', $run->status);
        $this->assertStringContainsString('app', (string) $run->log);
        $this->assertCount(0, $run->artifacts);
        $this->assertNotNull($run->finished_at);
    }

    public function test_job_records_an_error_when_database_enumeration_fails(): void
    {
        $connection = Connection::factory()->postgres()->create(['host' => '127.0.0.1', 'port' => 1]);

        dispatch_sync(new RunBackupJob($connection->id)); // no databases → must enumerate

        $run = BackupRun::firstOrFail();
        $this->assertSame('failed', $run->status);
        $this->assertNotNull($run->error);
    }

    public function test_command_rejects_an_unknown_connection(): void
    {
        $this->artisan('backups:run', ['connection' => 999999])
            ->assertExitCode(1);
    }

    public function test_command_queues_a_backup_job(): void
    {
        Queue::fake();
        $connection = Connection::factory()->postgres()->create();

        $this->artisan('backups:run', ['connection' => $connection->id])
            ->assertExitCode(0);

        Queue::assertPushed(RunBackupJob::class);
    }
}
