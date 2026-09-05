<?php

namespace App\Models;

use Database\Factories\BackupArtifactFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class BackupArtifact extends Model
{
    /** @use HasFactory<BackupArtifactFactory> */
    use HasFactory;

    use SoftDeletes;

    protected $fillable = [
        'backup_run_id', 'destination_id', 'database', 'disk', 'path',
        'size_bytes', 'checksum', 'compressed',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'size_bytes' => 'integer',
            'compressed' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<BackupRun, $this>
     */
    public function run(): BelongsTo
    {
        return $this->belongsTo(BackupRun::class, 'backup_run_id');
    }

    /**
     * Where this artifact actually lives. Null means the local staging disk.
     *
     * @return BelongsTo<Destination, $this>
     */
    public function destination(): BelongsTo
    {
        return $this->belongsTo(Destination::class);
    }

    /**
     * True when the dump was shipped off the machine that produced it, which
     * is the only state that survives losing that machine.
     */
    public function isRemote(): bool
    {
        return $this->destination_id !== null;
    }
}
