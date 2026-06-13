<?php

namespace App\Services\Destination;

use App\Concerns\RedactsSecrets;
use App\Models\Destination;
use App\Services\TestResult;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

class DestinationTester
{
    use RedactsSecrets;

    public function __construct(private DestinationManager $manager) {}

    /**
     * Verify the destination is reachable and writable, never throwing.
     */
    public function test(Destination $destination): TestResult
    {
        $start = microtime(true);

        try {
            match ($destination->type) {
                's3' => $this->testS3($destination),
                'telegram' => $this->testTelegram($destination),
                default => throw new RuntimeException("Unknown destination type [{$destination->type}]."),
            };

            return new TestResult(true, null, $this->elapsedMs($start));
        } catch (Throwable $e) {
            return new TestResult(false, $this->redactMessage($e->getMessage(), $destination), $this->elapsedMs($start));
        }
    }

    /**
     * Round-trip a tiny probe object to prove the bucket is writable+deletable.
     */
    private function testS3(Destination $destination): void
    {
        $disk = $this->manager->disk($destination);
        $probe = '__backup_probe_'.bin2hex(random_bytes(6)).'.txt';

        $disk->put($probe, 'db-backup-manager connectivity probe');
        $disk->delete($probe);
    }

    /**
     * Validate the bot token (getMe) and that it can post to the chat.
     */
    private function testTelegram(Destination $destination): void
    {
        $config = $destination->config ?? [];
        $token = (string) ($config['bot_token'] ?? '');
        $chatId = (string) ($config['chat_id'] ?? '');
        $base = "https://api.telegram.org/bot{$token}";

        $me = Http::timeout(10)->get("{$base}/getMe");
        if (! $me->ok() || $me->json('ok') !== true) {
            throw new RuntimeException('Telegram rejected the bot token.');
        }

        $sent = Http::timeout(10)->asForm()->post("{$base}/sendMessage", [
            'chat_id' => $chatId,
            'text' => '✅ db-backup-manager is connected to this channel.',
        ]);
        if (! $sent->ok() || $sent->json('ok') !== true) {
            throw new RuntimeException(
                'Cannot post to chat: '.($sent->json('description') ?? 'check the chat id and that the bot is a member.')
            );
        }
    }

    private function redactMessage(string $message, Destination $destination): string
    {
        $secrets = array_map(
            fn (string $key): string => (string) ($destination->config[$key] ?? ''),
            Destination::secretKeysFor($destination->type),
        );

        return $this->redactStrings($message, $secrets);
    }

    private function elapsedMs(float $start): int
    {
        return (int) round((microtime(true) - $start) * 1000);
    }
}
