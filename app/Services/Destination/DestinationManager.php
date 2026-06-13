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
    public function disk(Destination $destination): Filesystem
    {
        return match ($destination->type) {
            's3' => $this->s3Disk($destination),
            default => throw new InvalidArgumentException(
                "Destination type [{$destination->type}] is not a filesystem disk."
            ),
        };
    }

    private function s3Disk(Destination $destination): Filesystem
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
            'http' => ['connect_timeout' => 5, 'timeout' => 30],
        ]);
    }
}
