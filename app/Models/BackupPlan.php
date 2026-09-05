<?php

namespace App\Models;

use Cron\CronExpression;
use Database\Factories\BackupPlanFactory;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BackupPlan extends Model
{
    /** @use HasFactory<BackupPlanFactory> */
    use HasFactory;

    protected $fillable = [
        'name', 'connection_id', 'selection',
        'databases', 'exclude_databases', 'cron', 'timezone',
        'retention_days', 'retention_copies', 'enabled',
        'last_run_at', 'next_run_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'databases' => 'array',
            'exclude_databases' => 'array',
            'enabled' => 'boolean',
            'retention_days' => 'integer',
            'retention_copies' => 'integer',
            'last_run_at' => 'datetime',
            'next_run_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Connection, $this>
     */
    public function connection(): BelongsTo
    {
        return $this->belongsTo(Connection::class);
    }

    /**
     * Everywhere this plan's dumps are delivered.
     *
     * A plan with none still runs — the dumps stay on the local staging disk,
     * which only survives failures that leave this machine intact.
     *
     * @return BelongsToMany<Destination, $this>
     */
    public function destinations(): BelongsToMany
    {
        return $this->belongsToMany(Destination::class)->withTimestamps();
    }

    /**
     * @return HasMany<BackupRun, $this>
     */
    public function runs(): HasMany
    {
        return $this->hasMany(BackupRun::class);
    }

    public function isDue(DateTimeInterface $now): bool
    {
        return (new CronExpression($this->cron))->isDue($now, $this->timezone);
    }

    public function nextRunAfter(DateTimeInterface $now): DateTimeInterface
    {
        return (new CronExpression($this->cron))->getNextRunDate($now, 0, false, $this->timezone);
    }

    /**
     * Databases the job should dump. An empty list means "every database on the
     * server" (the job enumerates them at run time).
     *
     * @return list<string>
     */
    public function databasesForBackup(): array
    {
        return $this->selection === 'selected' ? array_values($this->databases ?? []) : [];
    }
}
