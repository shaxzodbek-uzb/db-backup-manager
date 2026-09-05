<?php

namespace App\Services\Destination;

use App\Models\Destination;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;

/**
 * Turns a Destination record into a runtime Flysystem disk.
 *
 * Only filesystem-style destinations (S3-compatible: AWS S3, DigitalOcean
 * Spaces, MinIO, …) map to a disk. Telegram is delivered over the Bot API and
 * is handled directly by the tester / backup engine, not through Flysystem.
 */
class DestinationManager
{
    /**
     * Request timeout for a probe. Short on purpose: the connectivity test
     * should say "unreachable" quickly rather than hang the operator.
     */
    public const PROBE_TIMEOUT = 30;

    /**
     * Request timeout for an upload. A database dump is orders of magnitude
     * larger than the probe object, and cutting a 200 MB upload off at the
     * probe's 30 seconds would report a healthy bucket as a failed backup.
     */
    public const UPLOAD_TIMEOUT = 3600;

    public function disk(Destination $destination, int $timeout = self::PROBE_TIMEOUT): Filesystem
    {
        return match ($destination->type) {
            's3' => $this->s3Disk($destination, $timeout),
            default => throw new InvalidArgumentException(
                "Destination type [{$destination->type}] is not a filesystem disk."
            ),
        };
    }

    private function s3Disk(Destination $destination, int $timeout): Filesystem
    {
        $config = $destination->config ?? [];

        return Storage::build([
            'driver' => 's3',
            'key' => $config['access_key'] ?? null,
            'secret' => $config['secret_key'] ?? null,
            'region' => $config['region'] ?? null,
            'bucket' => $config['bucket'] ?? null,
            'endpoint' => $config['endpoint'] ?: null,
            'use_path_style_endpoint' => (bool) ($config['use_path_style'] ?? false),
            'root' => trim((string) ($config['prefix'] ?? ''), '/'),
            'throw' => true,
            // Fail fast instead of the SDK's default multi-retry backoff.
            'retries' => 0,
            'http' => ['connect_timeout' => 5, 'timeout' => $timeout],
        ]);
    }
}
