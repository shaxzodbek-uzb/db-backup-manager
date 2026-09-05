<?php

namespace Tests\Feature;

use App\Models\Destination;
use App\Services\Destination\DestinationManager;
use App\Services\Destination\DestinationUploader;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Telegram allows roughly 20 messages a minute to one group, and a real run of
 * 132 dumps hit that hard: 53 of them came back "Too Many Requests" and were
 * recorded as failures even though the object-storage copy had landed fine.
 */
class TelegramRateLimitTest extends TestCase
{
    use RefreshDatabase;

    private string $dump;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dump = tempnam(sys_get_temp_dir(), 'dump');
        file_put_contents($this->dump, 'a-database-dump');
    }

    protected function tearDown(): void
    {
        @unlink($this->dump);

        parent::tearDown();
    }

    /**
     * The uploader with both waits stubbed out, recording what it was asked to
     * wait for so the timing can be asserted without spending it.
     */
    private function uploader(): DestinationUploader
    {
        return new class(app(DestinationManager::class)) extends DestinationUploader
        {
            /** @var list<string> */
            public array $waits = [];

            protected function sleepSeconds(int $seconds): void
            {
                $this->waits[] = "s:{$seconds}";
            }

            protected function sleepMilliseconds(int $milliseconds): void
            {
                $this->waits[] = "ms:{$milliseconds}";
            }
        };
    }

    private function accepted(int $size): array
    {
        return ['ok' => true, 'result' => ['document' => ['file_id' => 'file-id', 'file_size' => $size]]];
    }

    public function test_a_rate_limited_upload_waits_the_time_telegram_asks_for_and_succeeds(): void
    {
        $size = filesize($this->dump);

        Http::fakeSequence()
            ->push(['ok' => false, 'description' => 'Too Many Requests: retry after 15', 'parameters' => ['retry_after' => 15]], 429)
            ->push($this->accepted($size));

        $uploader = $this->uploader();
        $destination = Destination::factory()->telegram()->create();

        $fileId = $uploader->upload($destination, $this->dump, 'backups/1/1/app.sql.gz', $size);

        $this->assertSame('file-id', $fileId);
        // 15 from Telegram plus one second of slack.
        $this->assertContains('s:16', $uploader->waits);
    }

    public function test_it_gives_up_after_repeated_rate_limits(): void
    {
        $size = filesize($this->dump);

        Http::fake([
            'api.telegram.org/*' => Http::response(
                ['ok' => false, 'description' => 'Too Many Requests: retry after 9', 'parameters' => ['retry_after' => 9]],
                429,
            ),
        ]);

        $uploader = $this->uploader();
        $destination = Destination::factory()->telegram()->create();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('still refused after');

        $uploader->upload($destination, $this->dump, 'backups/1/1/app.sql.gz', $size);
    }

    public function test_consecutive_uploads_are_spaced_out(): void
    {
        $size = filesize($this->dump);

        Http::fake(['api.telegram.org/*' => Http::response($this->accepted($size))]);

        $uploader = $this->uploader();
        $destination = Destination::factory()->telegram()->create();

        $uploader->upload($destination, $this->dump, 'backups/1/1/a.sql.gz', $size);
        $this->assertSame([], $uploader->waits, 'the first upload should not wait');

        $uploader->upload($destination, $this->dump, 'backups/1/1/b.sql.gz', $size);

        $this->assertCount(1, $uploader->waits, 'the second upload should be spaced from the first');
        $this->assertStringStartsWith('ms:', $uploader->waits[0]);
    }

    public function test_a_non_rate_limit_error_is_not_retried(): void
    {
        $size = filesize($this->dump);

        // A wrong chat id must fail immediately: retrying it four times just
        // makes a run take four times as long to report the same thing.
        Http::fake([
            'api.telegram.org/*' => Http::response(['ok' => false, 'description' => 'chat not found'], 400),
        ]);

        $uploader = $this->uploader();
        $destination = Destination::factory()->telegram()->create();

        try {
            $uploader->upload($destination, $this->dump, 'backups/1/1/app.sql.gz', $size);
            $this->fail('expected the upload to throw');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('chat not found', $e->getMessage());
        }

        Http::assertSentCount(1);
    }
}
