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
        {--destination=* : Destination ids to upload to; repeat for several (omit to keep the dumps local)}
        {--sync : Run immediately instead of queueing}';

    protected $description = 'Back up all or selected databases on a connection';

    public function handle(): int
    {
        $connection = Connection::find($this->argument('connection'));

        if ($connection === null) {
            $this->error('Connection not found.');

            return self::FAILURE;
        }

        $destinationIds = array_values(array_filter(
            array_map('strval', (array) $this->option('destination')),
            fn (string $id): bool => $id !== '',
        ));

        /** @var list<int> $found */
        $found = array_map('intval', Destination::whereIn('id', $destinationIds)->pluck('id')->all());
        $missing = array_diff($destinationIds, array_map('strval', $found));

        if ($missing !== []) {
            $this->error('Destination ['.implode(', ', $missing).'] not found.');

            return self::FAILURE;
        }

        /** @var list<string> $databases */
        $databases = $this->option('database');

        $job = new RunBackupJob($connection->id, $databases, 'manual', null, $found);

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
