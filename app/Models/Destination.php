<?php

namespace App\Models;

use Database\Factories\DestinationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $name
 * @property string $type
 * @property array<string, mixed> $config
 * @property Carbon|null $last_tested_at
 * @property bool|null $last_test_ok
 * @property string|null $last_test_error
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'type', 'config'])]
#[Hidden(['config'])]
class Destination extends Model
{
    /** @use HasFactory<DestinationFactory> */
    use HasFactory;

    public const TYPES = ['s3', 'telegram'];

    /**
     * Config keys persisted per destination type (order = form order).
     *
     * @var array<string, list<string>>
     */
    public const CONFIG_KEYS = [
        's3' => ['region', 'bucket', 'access_key', 'secret_key', 'endpoint', 'prefix', 'use_path_style'],
        'telegram' => ['chat_id', 'bot_token'],
    ];

    /**
     * Secret config keys — write-only, never returned to the frontend, and only
     * overwritten on update when a non-empty value is submitted.
     *
     * @var array<string, list<string>>
     */
    public const SECRET_KEYS = [
        's3' => ['secret_key'],
        'telegram' => ['bot_token'],
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'config' => 'encrypted:array',
            'last_tested_at' => 'datetime',
            'last_test_ok' => 'boolean',
        ];
    }

    /**
     * @return list<string>
     */
    public static function secretKeysFor(string $type): array
    {
        return self::SECRET_KEYS[$type] ?? [];
    }

    /**
     * Config with secret values stripped — safe to send to the frontend.
     *
     * @return array<string, mixed>
     */
    public function safeConfig(): array
    {
        return Arr::except($this->config ?? [], static::secretKeysFor($this->type));
    }

    /**
     * Map of secret key => whether a value is stored, for "set / not set" UI.
     *
     * @return array<string, bool>
     */
    public function secretFlags(): array
    {
        $config = $this->config ?? [];

        return collect(static::secretKeysFor($this->type))
            ->mapWithKeys(fn (string $key): array => [$key => filled($config[$key] ?? null)])
            ->all();
    }
}
