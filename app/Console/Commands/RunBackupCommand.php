<?php

namespace App\Console\Commands;

use App\Jobs\RunBackupJob;
use App\Models\Connection;
use App\Models\Destination;
use Illuminate\Console\Command;

class RunBackupCommand extends Command
{
    protected $signature = 'backups:run
        {connection : The connection id}
        {--database=* : Specific databases to back up (default: all)}
        {--destination= : Destination id to upload to (omit to keep the dumps local)}
        {--sync : Run immediately instead of queueing}';

    protected $description = 'Back up all or selected databases on a connection';

    public function handle(): int
    {
        $connection = Connection::find($this->argument('connection'));

        if ($connection === null) {
            $this->error('Connection not found.');

            return self::FAILURE;
        }

        $destinationId = $this->option('destination');

        if ($destinationId !== null && $destinationId !== '') {
            if (Destination::find($destinationId) === null) {
                $this->error("Destination [{$destinationId}] not found.");

                return self::FAILURE;
            }
        } else {
            $destinationId = null;
        }

        /** @var list<string> $databases */
        $databases = $this->option('database');

        $job = new RunBackupJob($connection->id, $databases, 'manual', null, $destinationId !== null ? (int) $destinationId : null);

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
