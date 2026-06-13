<?php

namespace App\Jobs;

use App\Concerns\RedactsSecrets;
use App\Models\BackupPlan;
use App\Models\BackupRun;
use App\Models\Connection;
use App\Services\Backup\DatabaseDumper;
use App\Services\Backup\RetentionManager;
use App\Services\Db\DatabaseLister;
use App\Services\Ssh\SshTunnel;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
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
     */
    public function __construct(
        public int $connectionId,
        public array $databases = [],
        public string $trigger = 'manual',
        public ?int $backupPlanId = null,
    ) {}

    public function handle(
        DatabaseDumper $dumper,
        DatabaseLister $lister,
        SshTunnel $tunnel,
        RetentionManager $retention,
    ): void {
        $connection = Connection::findOrFail($this->connectionId);

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
                try {
                    $path = $this->stagingPath($connection, $run, $database);
                    $dumper->dump($connection, $database, $path, $endpoint);
                    $size = (int) (@filesize($path) ?: 0);

                    $run->artifacts()->create([
                        'database' => $database,
                        'disk' => 'local',
                        'path' => $this->relativePath($connection, $run, $database),
                        'size_bytes' => $size,
                        'compressed' => true,
                    ]);

                    $totalBytes += $size;
                    $log[] = sprintf('OK   %s (%s)', $database, $this->humanBytes($size));
                } catch (Throwable $e) {
                    $failed++;
                    $log[] = sprintf('FAIL %s: %s', $database, $this->redactSecrets($e->getMessage(), $connection));
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
