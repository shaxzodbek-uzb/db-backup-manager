<?php

namespace App\Jobs;

use App\Concerns\RedactsSecrets;
use App\Models\BackupArtifact;
use App\Models\BackupPlan;
use App\Models\BackupRun;
use App\Models\Connection;
use App\Models\Destination;
use App\Services\Backup\DatabaseDumper;
use App\Services\Backup\RetentionManager;
use App\Services\Db\DatabaseLister;
use App\Services\Destination\DestinationManager;
use App\Services\Ssh\SshTunnel;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use RuntimeException;
use Throwable;

class RunBackupJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use RedactsSecrets;
    use SerializesModels;

    /** Allow long-running dumps. */
    public int $timeout = 3600;

    /**
     * @param  list<string>  $databases  Empty = every database on the server.
     * @param  int|null  $destinationId  Where the dumps are shipped. Null keeps
     *                                   them on the local staging disk, which
     *                                   only survives failures that leave this
     *                                   machine intact.
     */
    public function __construct(
        public int $connectionId,
        public array $databases = [],
        public string $trigger = 'manual',
        public ?int $backupPlanId = null,
        public ?int $destinationId = null,
    ) {}

    public function handle(
        DatabaseDumper $dumper,
        DatabaseLister $lister,
        SshTunnel $tunnel,
        RetentionManager $retention,
        DestinationManager $destinations,
    ): void {
        $connection = Connection::findOrFail($this->connectionId);

        // Resolved before the first dump: an hour of pg_dump is wasted if the
        // bucket it is meant to land in does not exist, and the failure then
        // reads as a broken database rather than a destination nobody finished
        // setting up.
        $destination = $this->destinationId !== null
            ? Destination::find($this->destinationId)
            : null;

        if ($this->destinationId !== null && $destination === null) {
            throw new RuntimeException(
                "Destination [{$this->destinationId}] no longer exists — refusing to run a backup with nowhere to put it."
            );
        }

        $run = BackupRun::create([
            'connection_id' => $connection->id,
            'backup_plan_id' => $this->backupPlanId,
            'trigger' => $this->trigger,
            'status' => 'running',
            'databases' => $this->databases,
            'started_at' => now(),
        ]);

        $log = [];
        $started = microtime(true);

        try {
            $databases = $this->databases !== []
                ? $this->databases
                : array_column($lister->list($connection), 'name');

            $run->update(['databases' => $databases]);

            $endpoint = $connection->ssh_enabled ? $tunnel->open($connection) : null;
            $totalBytes = 0;
            $failed = 0;

            foreach ($databases as $database) {
                $staging = $this->stagingPath($connection, $run, $database);

                try {
                    $dumper->dump($connection, $database, $staging, $endpoint);
                    $size = (int) (@filesize($staging) ?: 0);

                    $artifact = $this->store(
                        $run,
                        $connection,
                        $destination,
                        $destinations,
                        $database,
                        $staging,
                        $size,
                    );

                    $totalBytes += $size;
                    $log[] = sprintf(
                        'OK   %s (%s) → %s',
                        $database,
                        $this->humanBytes($size),
                        $artifact->isRemote() ? $destination->name ?? 'destination' : 'local',
                    );
                } catch (Throwable $e) {
                    $failed++;
                    $log[] = sprintf('FAIL %s: %s', $database, $this->redactSecrets($e->getMessage(), $connection));

                    // A dump that could not be delivered is not a backup, and a
                    // half-written staging file is worse than none: it looks
                    // like one to anybody reading the disk.
                    if (is_file($staging)) {
                        @unlink($staging);
                    }
                }
            }

            $run->update([
                'status' => $this->resolveStatus(count($databases), $failed),
                'finished_at' => now(),
                'duration_seconds' => (int) round(microtime(true) - $started),
                'total_bytes' => $totalBytes,
                'log' => implode("\n", $log),
            ]);

            if ($this->backupPlanId !== null) {
                $plan = BackupPlan::find($this->backupPlanId);
                if ($plan !== null) {
                    $retention->prune($plan);
                }
            }
        } catch (Throwable $e) {
            $run->update([
                'status' => 'failed',
                'finished_at' => now(),
                'duration_seconds' => (int) round(microtime(true) - $started),
                'error' => $this->redactSecrets($e->getMessage(), $connection),
                'log' => implode("\n", $log),
            ]);
        } finally {
            $tunnel->close();
        }
    }

    /**
     * Records the finished dump, shipping it to the destination when there is
     * one.
     *
     * The upload is verified rather than assumed: Flysystem reports a write as
     * successful the moment the SDK call returns, so the object's size is read
     * back and compared with the file that was sent. A truncated upload that is
     * recorded as a backup is the failure this whole command exists to prevent.
     *
     * The staging file is removed only once the object is known to be there.
     */
    private function store(
        BackupRun $run,
        Connection $connection,
        ?Destination $destination,
        DestinationManager $destinations,
        string $database,
        string $staging,
        int $size,
    ): BackupArtifact {
        $relative = $this->relativePath($connection, $run, $database);

        if ($destination === null) {
            return $run->artifacts()->create([
                'database' => $database,
                'disk' => 'local',
                'path' => $relative,
                'size_bytes' => $size,
                'compressed' => true,
            ]);
        }

        $disk = $destinations->disk($destination, DestinationManager::UPLOAD_TIMEOUT);
        $stream = fopen($staging, 'rb');

        if ($stream === false) {
            throw new RuntimeException("Dump file [{$staging}] could not be opened for upload.");
        }

        try {
            $disk->writeStream($relative, $stream);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        $uploaded = (int) $disk->size($relative);

        if ($uploaded !== $size) {
            // Leave the staging copy in place: it is now the only complete one.
            throw new RuntimeException(sprintf(
                'Upload of %s is %d bytes but the dump is %d — the copy at the destination is incomplete.',
                $database,
                $uploaded,
                $size,
            ));
        }

        @unlink($staging);

        return $run->artifacts()->create([
            'destination_id' => $destination->id,
            'database' => $database,
            'disk' => $destination->type,
            'path' => $relative,
            'size_bytes' => $size,
            'compressed' => true,
        ]);
    }

    private function resolveStatus(int $total, int $failed): string
    {
        return match (true) {
            $failed === 0 => 'success',
            $failed >= $total => 'failed',
            default => 'partial',
        };
    }

    private function stagingPath(Connection $connection, BackupRun $run, string $database): string
    {
        return storage_path('app/'.$this->relativePath($connection, $run, $database));
    }

    private function relativePath(Connection $connection, BackupRun $run, string $database): string
    {
        $base = trim((string) config('backup.staging_path', 'backups'), '/');

        return sprintf('%s/%d/%d/%s.sql.gz', $base, $connection->id, $run->id, $this->safeName($database));
    }

    private function safeName(string $database): string
    {
        return preg_replace('/[^A-Za-z0-9_.-]/', '_', $database) ?: 'database';
    }

    private function humanBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i = $bytes > 0 ? (int) floor(log($bytes, 1024)) : 0;
        $i = min($i, count($units) - 1);

        return round($bytes / (1024 ** $i), 2).' '.$units[$i];
    }
}
