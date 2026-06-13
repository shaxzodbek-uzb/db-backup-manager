<?php

namespace App\Models;

use Database\Factories\BackupRunFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BackupRun extends Model
{
    /** @use HasFactory<BackupRunFactory> */
    use HasFactory;

    protected $fillable = [
        'connection_id', 'trigger', 'status', 'databases',
        'started_at', 'finished_at', 'duration_seconds', 'total_bytes',
        'log', 'error',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'databases' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'duration_seconds' => 'integer',
            'total_bytes' => 'integer',
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
     * @return HasMany<BackupArtifact, $this>
     */
    public function artifacts(): HasMany
    {
        return $this->hasMany(BackupArtifact::class);
    }
}
