<?php

namespace Tests\Feature;

use App\Jobs\RunBackupJob;
use App\Models\BackupPlan;
use App\Models\BackupRun;
use App\Models\Connection;
use App\Models\Destination;
use App\Services\Backup\DatabaseDumper;
use App\Services\Backup\RetentionManager;
use App\Services\Destination\DestinationManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Mockery;
use RuntimeException;
use Tests\TestCase;

/**
 * Covers the step that turns a dump into a backup: getting it off the machine
 * that produced it. Before this existed every artifact was written to the local
 * staging disk and the destination records were only ever used by the
 * connectivity test, so a configured bucket stayed empty while the runs were
 * reported as successful.
 */
class BackupUploadTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Stands in for the real dumper by writing $contents where the job expects
     * the dump, so the upload path can be tested without a live database.
     */
    private function fakeDumperWriting(string $contents): void
    {
        $this->mock(DatabaseDumper::class, function ($mock) use ($contents) {
            $mock->shouldReceive('dump')->andReturnUsing(
                function ($connection, $database, $path) use ($contents) {
                    @mkdir(dirname($path), 0o755, true);
                    file_put_contents($path, $contents);

                    return $path;
                }
            );
        });
    }

    private function fakeDestinationDisk(mixed $disk): void
    {
        $this->mock(DestinationManager::class, function ($mock) use ($disk) {
            $mock->shouldReceive('disk')->andReturn($disk);
        });
    }

    public function test_dump_is_uploaded_to_the_destination_and_staging_is_removed(): void
    {
        $disk = Storage::fake('destination');
        $this->fakeDumperWriting('a-database-dump');
        $this->fakeDestinationDisk($disk);

        $connection = Connection::factory()->postgres()->create();
        $destination = Destination::factory()->create();

        dispatch_sync(new RunBackupJob($connection->id, ['app'], 'manual', null, [$destination->id]));

        $run = BackupRun::firstOrFail();
        $this->assertSame('success', $run->status);

        $artifact = $run->artifacts()->firstOrFail();
        $this->assertTrue($artifact->isRemote());
        $this->assertSame($destination->id, $artifact->destination_id);
        $this->assertSame('s3', $artifact->disk);
        $this->assertSame(strlen('a-database-dump'), $artifact->size_bytes);

        $disk->assertExists($artifact->path);
        $this->assertFileDoesNotExist(storage_path('app/'.$artifact->path));
    }

    public function test_dumps_stay_local_when_no_destination_is_given(): void
    {
        $this->fakeDumperWriting('a-database-dump');

        $connection = Connection::factory()->postgres()->create();

        dispatch_sync(new RunBackupJob($connection->id, ['app']));

        $artifact = BackupRun::firstOrFail()->artifacts()->firstOrFail();

        $this->assertFalse($artifact->isRemote());
        $this->assertNull($artifact->destination_id);
        $this->assertSame('local', $artifact->disk);
        $this->assertFileExists(storage_path('app/'.$artifact->path));
    }

    public function test_a_truncated_upload_is_a_failure_not_a_backup(): void
    {
        // Flysystem reports the write as fine; only reading the object back
        // shows it is short. This is the case that must never be recorded as a
        // successful backup.
        $disk = Storage::fake('destination');

        $this->fakeDumperWriting('a-much-longer-database-dump');

        // Partial mock: every call but size() goes to the real fake disk, so
        // the write genuinely succeeds and only the read-back disagrees.
        $shortReader = Mockery::mock($disk)->makePartial();
        $shortReader->shouldReceive('size')->andReturn(1);
        $this->fakeDestinationDisk($shortReader);

        $connection = Connection::factory()->postgres()->create();
        $destination = Destination::factory()->create();

        dispatch_sync(new RunBackupJob($connection->id, ['app'], 'manual', null, [$destination->id]));

        $run = BackupRun::firstOrFail();

        $this->assertSame('failed', $run->status);
        $this->assertCount(0, $run->artifacts);
        $this->assertStringContainsString('incomplete', (string) $run->log);
    }

    public function test_job_refuses_to_run_when_the_destination_is_missing(): void
    {
        $connection = Connection::factory()->postgres()->create();

        // Throwing rather than falling back to a local dump is deliberate: the
        // queue records the failure, whereas a silent downgrade would keep
        // reporting successful runs while nothing left the machine.
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('nowhere to put it');

        try {
            dispatch_sync(new RunBackupJob($connection->id, ['app'], 'manual', null, [9999]));
        } finally {
            // Nothing was dumped: the job never got past resolving where it goes.
            $this->assertSame(0, BackupRun::count());
        }
    }

    public function test_retention_deletes_the_object_from_its_destination(): void
    {
        $disk = Storage::fake('destination');
        $disk->put('backups/1/1/app.sql.gz', 'old-dump');
        $this->fakeDestinationDisk($disk);

        $destination = Destination::factory()->create();
        $plan = BackupPlan::factory()->create(['retention_copies' => 0]);

        $run = BackupRun::factory()->create([
            'backup_plan_id' => $plan->id,
            'status' => 'success',
        ]);

        $run->artifacts()->create([
            'destination_id' => $destination->id,
            'database' => 'app',
            'disk' => 's3',
            'path' => 'backups/1/1/app.sql.gz',
            'size_bytes' => 8,
            'compressed' => true,
        ]);

        app(RetentionManager::class)->prune($plan->fresh());

        $disk->assertMissing('backups/1/1/app.sql.gz');
        $this->assertCount(0, $run->fresh()->artifacts);
    }
}
