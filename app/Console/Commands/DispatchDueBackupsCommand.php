<?php

namespace App\Console\Commands;

use App\Jobs\RunBackupJob;
use App\Models\BackupPlan;
use Illuminate\Console\Command;

class DispatchDueBackupsCommand extends Command
{
    protected $signature = 'backups:dispatch-due';

    protected $description = 'Dispatch a backup for every enabled schedule that is due now';

    public function handle(): int
    {
        $now = now();
        $dispatched = 0;

        foreach (BackupPlan::query()->with('destinations')->where('enabled', true)->get() as $plan) {
            if (! $plan->isDue($now)) {
                continue;
            }

            RunBackupJob::dispatch(
                $plan->connection_id,
                $plan->databasesForBackup(),
                'scheduled',
                $plan->id,
                array_values(array_map('intval', $plan->destinations->pluck('id')->all())),
            );

            $plan->forceFill([
                'last_run_at' => $now,
                'next_run_at' => $plan->nextRunAfter($now),
            ])->save();

            $this->info("Dispatched: [{$plan->name}]");
            $dispatched++;
        }

        $this->info("{$dispatched} backup(s) dispatched.");

        return self::SUCCESS;
    }
}
