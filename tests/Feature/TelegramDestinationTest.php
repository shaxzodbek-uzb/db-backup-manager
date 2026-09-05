<?php

namespace Tests\Feature;

use App\Jobs\RunBackupJob;
use App\Models\BackupRun;
use App\Models\Connection;
use App\Models\Destination;
use App\Services\Backup\DatabaseDumper;
use App\Services\Destination\DestinationManager;
use App\Services\Destination\DestinationUploader;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Telegram delivery, and the case a single-destination plan could not express:
 * one dump going to object storage and a chat at the same time.
 */
class TelegramDestinationTest extends TestCase
{
    use RefreshDatabase;

    private const DUMP = 'a-database-dump';

    private function fakeDumper(string $contents = self::DUMP): void
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

    /**
     * Telegram's sendDocument echoes the stored document back; the uploader
     * compares its size with what was sent.
     */
    private function fakeTelegramAccepting(int $storedBytes): void
    {
        Http::fake([
            'api.telegram.org/*' => Http::response([
                'ok' => true,
                'result' => ['document' => ['file_id' => 'BQACAgIAAx0-file-id', 'file_size' => $storedBytes]],
            ]),
        ]);
    }

    public function test_dump_is_sent_to_telegram_and_the_file_id_is_recorded(): void
    {
        $this->fakeDumper();
        $this->fakeTelegramAccepting(strlen(self::DUMP));

        $connection = Connection::factory()->postgres()->create();
        $destination = Destination::factory()->telegram()->create();

        dispatch_sync(new RunBackupJob($connection->id, ['app'], 'manual', null, [$destination->id]));

        $run = BackupRun::firstOrFail();
        $this->assertSame('success', $run->status);

        $artifact = $run->artifacts()->firstOrFail();
        $this->assertSame('telegram', $artifact->disk);
        // Telegram has no path — the file_id is the only handle to the document.
        $this->assertSame('BQACAgIAAx0-file-id', $artifact->path);
        $this->assertTrue($artifact->isRemote());

        $this->assertFileDoesNotExist(storage_path('app/'.$artifact->path));

        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/sendDocument'));
    }

    public function test_a_dump_over_fifty_megabytes_is_a_failure(): void
    {
        // The old system skipped these silently and two customer databases went
        // unbacked for months, so this has to be loud. The ceiling is checked
        // before the file is read, which is also why no 50 MB file is needed
        // to test it.
        $destination = Destination::factory()->telegram()->create();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Telegram refuses documents over');

        app(DestinationUploader::class)->upload(
            $destination,
            storage_path('app/never-read.sql.gz'),
            'backups/1/1/app.sql.gz',
            DestinationUploader::TELEGRAM_MAX_BYTES + 1,
        );
    }

    public function test_a_short_document_in_the_chat_is_a_failure(): void
    {
        $this->fakeDumper();
        // Telegram claims it stored one byte; the dump is longer than that.
        $this->fakeTelegramAccepting(1);

        $connection = Connection::factory()->postgres()->create();
        $destination = Destination::factory()->telegram()->create();

        dispatch_sync(new RunBackupJob($connection->id, ['app'], 'manual', null, [$destination->id]));

        $run = BackupRun::firstOrFail();

        $this->assertSame('failed', $run->status);
        $this->assertCount(0, $run->artifacts);
        $this->assertStringContainsString('incomplete', (string) $run->log);
    }

    public function test_one_dump_reaches_both_destinations(): void
    {
        $disk = Storage::fake('spaces');
        $this->fakeDumper();
        $this->fakeTelegramAccepting(strlen(self::DUMP));
        $this->mock(DestinationManager::class, function ($mock) use ($disk) {
            $mock->shouldReceive('disk')->andReturn($disk);
        });

        $connection = Connection::factory()->postgres()->create();
        $spaces = Destination::factory()->create(['name' => 'Spaces']);
        $telegram = Destination::factory()->telegram()->create(['name' => 'Backups chat']);

        dispatch_sync(new RunBackupJob(
            $connection->id, ['app'], 'manual', null, [$spaces->id, $telegram->id],
        ));

        $run = BackupRun::firstOrFail();
        $this->assertSame('success', $run->status);

        // One dump, two copies — not two dumps.
        $this->assertCount(2, $run->artifacts);
        $this->assertEqualsCanonicalizing(
            ['s3', 'telegram'],
            $run->artifacts->pluck('disk')->all(),
        );
        $this->assertStringContainsString('Spaces + Backups chat', (string) $run->log);

        $disk->assertExists('backups/'.$connection->id.'/'.$run->id.'/app.sql.gz');
    }

    public function test_failing_one_destination_fails_the_database(): void
    {
        $disk = Storage::fake('spaces');
        $this->fakeDumper();
        Http::fake([
            'api.telegram.org/*' => Http::response(['ok' => false, 'description' => 'chat not found'], 400),
        ]);
        $this->mock(DestinationManager::class, function ($mock) use ($disk) {
            $mock->shouldReceive('disk')->andReturn($disk);
        });

        $connection = Connection::factory()->postgres()->create();
        $spaces = Destination::factory()->create(['name' => 'Spaces']);
        $telegram = Destination::factory()->telegram()->create(['name' => 'Backups chat']);

        dispatch_sync(new RunBackupJob(
            $connection->id, ['app'], 'manual', null, [$spaces->id, $telegram->id],
        ));

        $run = BackupRun::firstOrFail();

        // Reaching only one of the two places asked for is not the outcome
        // requested, so the database counts as failed even though a copy exists.
        $this->assertSame('failed', $run->status);
        $this->assertStringContainsString('chat not found', (string) $run->log);
        $this->assertCount(1, $run->artifacts, 'the copy that did land is still recorded');
    }
}
