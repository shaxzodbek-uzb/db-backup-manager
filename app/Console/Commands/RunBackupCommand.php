<?php

namespace App\Console\Commands;

use App\Jobs\RunBackupJob;
use App\Models\Connection;
use Illuminate\Console\Command;

class RunBackupCommand extends Command
{
    protected $signature = 'backups:run
        {connection : The connection id}
        {--database=* : Specific databases to back up (default: all)}
        {--sync : Run immediately instead of queueing}';

    protected $description = 'Back up all or selected databases on a connection';

    public function handle(): int
    {
        $connection = Connection::find($this->argument('connection'));

        if ($connection === null) {
            $this->error('Connection not found.');

            return self::FAILURE;
        }

        /** @var list<string> $databases */
        $databases = $this->option('database');

        $job = new RunBackupJob($connection->id, $databases, 'manual');

        if ($this->option('sync')) {
            $this->info("Backing up [{$connection->name}]…");
            dispatch_sync($job);
            $this->info('Done.');
        } else {
            dispatch($job);
            $this->info("Backup queued for [{$connection->name}].");
        }

        return self::SUCCESS;
    }
}
