<?php

namespace App\Jobs;

use App\Concerns\RedactsSecrets;
use App\Models\BackupPlan;
use App\Models\BackupRun;
use App\Models\Connection;
use App\Models\Destination;
use App\Services\Backup\DatabaseDumper;
use App\Services\Backup\RetentionManager;
use App\Services\Db\DatabaseLister;
use App\Services\Destination\DestinationUploader;
use App\Services\Ssh\SshTunnel;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;
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
     * @param  list<int>  $destinationIds  Everywhere the dumps are shipped. Empty
     *                                     keeps them on the local staging disk,
     *                                     which only survives failures that
     *                                     leave this machine intact.
     */
    public function __construct(
        public int $connectionId,
        public array $databases = [],
        public string $trigger = 'manual',
        public ?int $backupPlanId = null,
        public array $destinationIds = [],
    ) {}

    public function handle(
        DatabaseDumper $dumper,
        DatabaseLister $lister,
        SshTunnel $tunnel,
        RetentionManager $retention,
        DestinationUploader $uploader,
    ): void {
        $connection = Connection::findOrFail($this->connectionId);

        // Resolved before the first dump: an hour of pg_dump is wasted if the
        // places it is meant to land do not exist, and the failure then reads
        // as a broken database rather than a destination nobody finished
        // setting up.
        $destinations = Destination::whereIn('id', $this->destinationIds)->get();

        $missing = array_diff($this->destinationIds, $destinations->pluck('id')->all());

        if ($missing !== []) {
            throw new RuntimeException(
                'Destination ['.implode(', ', $missing).'] no longer exists — refusing to run a backup with nowhere to put it.'
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

                    $delivered = $this->store(
                        $run,
                        $connection,
                        $destinations,
                        $uploader,
                        $database,
                        $staging,
                        $size,
                    );

                    $totalBytes += $size;
                    $log[] = sprintf(
                        'OK   %s (%s) → %s',
                        $database,
                        $this->humanBytes($size),
                        $delivered,
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
     * Records the finished dump and ships it everywhere it is meant to go.
     *
     * Delivery to every destination must succeed. A partial delivery is
     * reported as a failed database rather than a success with a footnote: the
     * whole point of a second destination is that the first one might be the
     * thing that is lost, so "it made it to one of them" is not the outcome
     * that was asked for.
     *
     * The staging file is removed only once every copy is confirmed.
     *
     * @param  Collection<int, Destination>  $destinations
     * @return string human-readable list of where it landed, for the run log
     */
    private function store(
        BackupRun $run,
        Connection $connection,
        $destinations,
        DestinationUploader $uploader,
        string $database,
        string $staging,
        int $size,
    ): string {
        $relative = $this->relativePath($connection, $run, $database);

        if ($destinations->isEmpty()) {
            $run->artifacts()->create([
                'database' => $database,
                'disk' => 'local',
                'path' => $relative,
                'size_bytes' => $size,
                'compressed' => true,
            ]);

            return 'local';
        }

        $created = [];

        foreach ($destinations as $destination) {
            // Not wrapped: a failure here propagates and the caller records the
            // database as failed. Artifacts already written for earlier
            // destinations stay — those copies genuinely exist.
            $storedPath = $uploader->upload($destination, $staging, $relative, $size);

            $run->artifacts()->create([
                'destination_id' => $destination->id,
                'database' => $database,
                'disk' => $destination->type,
                'path' => $storedPath,
                'size_bytes' => $size,
                'compressed' => true,
            ]);

            $created[] = $destination->name;
        }

        @unlink($staging);

        return implode(' + ', $created);
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
