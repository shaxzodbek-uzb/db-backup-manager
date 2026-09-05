<?php

namespace App\Services\Destination;

use App\Models\Destination;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Delivers a finished dump to a destination, whatever kind it is.
 *
 * S3-style destinations are a filesystem, Telegram is a Bot API upload, and the
 * backup engine should not have to know which. Both paths verify what arrived
 * rather than trusting the call to have worked: an upload reported as fine but
 * short is the failure this whole system exists to catch.
 */
class DestinationUploader
{
    /**
     * Telegram refuses documents over 50 MB and there is no way around it with
     * a bot token. Larger dumps are a failure here rather than a silent skip —
     * that silence is exactly why two customer databases went unbacked for
     * months under the old system.
     */
    public const TELEGRAM_MAX_BYTES = 50 * 1024 * 1024;

    /**
     * Telegram allows roughly 20 messages a minute to one group. Sending a
     * hundred dumps back to back walks straight into that: a run of 132 had 53
     * rejected with "Too Many Requests". Spacing the uploads keeps the whole
     * batch inside the limit instead of racing it and retrying the wreckage.
     */
    public const TELEGRAM_MIN_INTERVAL_MS = 3000;

    /**
     * How many times a rate-limited upload is retried. Telegram says how long
     * to wait, so these are patient waits rather than blind backoff.
     */
    public const TELEGRAM_MAX_ATTEMPTS = 4;

    /**
     * Unix milliseconds of the last document sent, per chat.
     *
     * @var array<string, int>
     */
    private array $lastSentAt = [];

    public function __construct(private DestinationManager $manager) {}

    /**
     * Send $localPath to $destination and return the path/identifier under
     * which it now lives there.
     */
    public function upload(Destination $destination, string $localPath, string $remotePath, int $sizeBytes): string
    {
        return match ($destination->type) {
            's3' => $this->uploadToS3($destination, $localPath, $remotePath, $sizeBytes),
            'telegram' => $this->uploadToTelegram($destination, $localPath, $remotePath, $sizeBytes),
            default => throw new RuntimeException(
                "Destination type [{$destination->type}] cannot receive backups."
            ),
        };
    }

    /**
     * Whether the destination can delete what was written to it.
     *
     * Telegram cannot: a bot may only delete its own messages for 48 hours, so
     * retention has nothing to act on there.
     */
    public function supportsDeletion(Destination $destination): bool
    {
        return $destination->type !== 'telegram';
    }

    private function uploadToS3(Destination $destination, string $localPath, string $remotePath, int $sizeBytes): string
    {
        $disk = $this->manager->disk($destination, DestinationManager::UPLOAD_TIMEOUT);
        $stream = fopen($localPath, 'rb');

        if ($stream === false) {
            throw new RuntimeException("Dump file [{$localPath}] could not be opened for upload.");
        }

        try {
            $disk->writeStream($remotePath, $stream);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        $uploaded = (int) $disk->size($remotePath);

        if ($uploaded !== $sizeBytes) {
            // Left in place by the caller: this copy is now the only complete one.
            throw new RuntimeException(sprintf(
                'Upload is %d bytes but the dump is %d — the copy at the destination is incomplete.',
                $uploaded,
                $sizeBytes,
            ));
        }

        return $remotePath;
    }

    /**
     * Returns the Telegram file_id, which is the only handle to a document
     * once it is in a chat — there is no path to list or fetch it by.
     */
    private function uploadToTelegram(Destination $destination, string $localPath, string $remotePath, int $sizeBytes): string
    {
        if ($sizeBytes > self::TELEGRAM_MAX_BYTES) {
            throw new RuntimeException(sprintf(
                'Dump is %s MB; Telegram refuses documents over %s MB.',
                round($sizeBytes / 1048576, 1),
                round(self::TELEGRAM_MAX_BYTES / 1048576),
            ));
        }

        $config = $destination->config ?? [];
        $token = (string) ($config['bot_token'] ?? '');
        $chatId = (string) ($config['chat_id'] ?? '');

        $response = $this->sendDocument($token, $chatId, $localPath, $remotePath);

        if (! $response->ok() || $response->json('ok') !== true) {
            throw new RuntimeException(
                'Telegram rejected the upload: '.($response->json('description') ?? 'unknown error')
            );
        }

        $document = $response->json('result.document');
        $fileId = $document['file_id'] ?? null;

        if (! is_string($fileId) || $fileId === '') {
            throw new RuntimeException('Telegram accepted the upload but returned no file_id.');
        }

        // Telegram reports the stored size back; a mismatch means the document
        // in the chat is not the dump that was sent.
        $stored = (int) ($document['file_size'] ?? 0);

        if ($stored !== $sizeBytes) {
            throw new RuntimeException(sprintf(
                'Telegram stored %d bytes but the dump is %d — the copy in the chat is incomplete.',
                $stored,
                $sizeBytes,
            ));
        }

        return $fileId;
    }

    /**
     * Posts the document, waiting out the rate limit when Telegram asks.
     *
     * Only 429 is retried: a wrong chat id would otherwise take four times as
     * long to report the same thing.
     */
    private function sendDocument(string $token, string $chatId, string $localPath, string $remotePath): Response
    {
        for ($attempt = 1; ; $attempt++) {
            $this->pauseForRateLimit($chatId);

            $stream = fopen($localPath, 'rb');

            if ($stream === false) {
                throw new RuntimeException("Dump file [{$localPath}] could not be opened for upload.");
            }

            try {
                $response = $this->telegramClient()
                    ->attach('document', $stream, basename($remotePath))
                    ->post("https://api.telegram.org/bot{$token}/sendDocument", [
                        'chat_id' => $chatId,
                        'caption' => $remotePath,
                        'disable_notification' => 'true',
                    ]);
            } finally {
                if (is_resource($stream)) {
                    fclose($stream);
                }
            }

            $this->lastSentAt[$chatId] = (int) (microtime(true) * 1000);

            $retryAfter = $this->retryAfterSeconds($response);

            if ($retryAfter === null) {
                return $response;
            }

            if ($attempt >= self::TELEGRAM_MAX_ATTEMPTS) {
                throw new RuntimeException(sprintf(
                    'Telegram is rate limiting this chat: still refused after %d attempts (last wait %ds).',
                    $attempt,
                    $retryAfter,
                ));
            }

            // Telegram names the wait; a second on top absorbs clock skew.
            $this->sleepSeconds($retryAfter + 1);
        }
    }

    /**
     * Returns the seconds Telegram asked us to wait, or null if it did not.
     */
    private function retryAfterSeconds(Response $response): ?int
    {
        if ($response->status() !== 429) {
            return null;
        }

        $retryAfter = $response->json('parameters.retry_after');

        return is_numeric($retryAfter) ? (int) $retryAfter : 1;
    }

    /**
     * Holds the next upload back far enough to stay under the per-chat limit.
     */
    private function pauseForRateLimit(string $chatId): void
    {
        $last = $this->lastSentAt[$chatId] ?? null;

        if ($last === null) {
            return;
        }

        $elapsed = (int) (microtime(true) * 1000) - $last;
        $remaining = self::TELEGRAM_MIN_INTERVAL_MS - $elapsed;

        if ($remaining > 0) {
            $this->sleepMilliseconds($remaining);
        }
    }

    /**
     * Both waits go through these so tests can run without real delays.
     */
    protected function sleepSeconds(int $seconds): void
    {
        sleep($seconds);
    }

    protected function sleepMilliseconds(int $milliseconds): void
    {
        usleep($milliseconds * 1000);
    }

    private function telegramClient(): PendingRequest
    {
        // Uploading a 50 MB document over a slow link takes far longer than an
        // API call, so this timeout is generous on purpose.
        return Http::timeout(DestinationManager::UPLOAD_TIMEOUT)->connectTimeout(10);
    }
}
