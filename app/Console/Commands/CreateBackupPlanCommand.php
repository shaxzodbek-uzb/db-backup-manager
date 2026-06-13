<?php

namespace App\Console\Commands;

use App\Models\BackupPlan;
use App\Models\Connection;
use Illuminate\Console\Command;

class CreateBackupPlanCommand extends Command
{
    protected $signature = 'backups:plan
        {connection : The connection id}
        {--name= : Plan name (default: "<connection> backup")}
        {--cron= : Cron expression (default: "0 2 * * *", daily 02:00)}
        {--database=* : Databases to back up (omit for all)}
        {--timezone=UTC : Timezone the cron is evaluated in}
        {--retention-copies= : Keep only the newest N backups}
        {--retention-days= : Delete backups older than N days}
        {--disabled : Create the plan disabled}';

    protected $description = 'Create a scheduled backup plan for a connection';

    public function handle(): int
    {
        $connection = Connection::find($this->argument('connection'));

        if ($connection === null) {
            $this->error('Connection not found.');

            return self::FAILURE;
        }

        /** @var list<string> $databases */
        $databases = $this->option('database');

        $plan = BackupPlan::create([
            'name' => $this->option('name') ?: $connection->name.' backup',
            'connection_id' => $connection->id,
            'selection' => $databases !== [] ? 'selected' : 'all',
            'databases' => $databases !== [] ? $databases : null,
            'cron' => $this->option('cron') ?: '0 2 * * *',
            'timezone' => $this->option('timezone') ?: 'UTC',
            'retention_copies' => $this->intOption('retention-copies'),
            'retention_days' => $this->intOption('retention-days'),
            'enabled' => ! $this->option('disabled'),
        ]);

        $plan->forceFill(['next_run_at' => $plan->nextRunAfter(now())])->save();

        $this->info("Plan #{$plan->id} [{$plan->name}] created — cron \"{$plan->cron}\", next run {$plan->next_run_at}.");

        return self::SUCCESS;
    }

    private function intOption(string $name): ?int
    {
        $value = $this->option($name);

        return $value !== null && $value !== '' ? (int) $value : null;
    }
}
