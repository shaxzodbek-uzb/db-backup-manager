<?php

namespace App\Models;

use Database\Factories\ConnectionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $name
 * @property string $driver
 * @property string $host
 * @property int $port
 * @property string $username
 * @property string|null $password
 * @property string|null $ssl_mode
 * @property string|null $ssl_ca
 * @property string|null $ssl_cert
 * @property string|null $ssl_key
 * @property bool $ssh_enabled
 * @property string|null $ssh_host
 * @property int $ssh_port
 * @property string|null $ssh_user
 * @property string|null $ssh_auth
 * @property string|null $ssh_private_key
 * @property string|null $ssh_passphrase
 * @property string|null $ssh_password
 * @property Carbon|null $last_tested_at
 * @property bool|null $last_test_ok
 * @property string|null $last_test_error
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'name', 'driver', 'host', 'port', 'username', 'password',
    'ssl_mode', 'ssl_ca', 'ssl_cert', 'ssl_key',
    'ssh_enabled', 'ssh_host', 'ssh_port', 'ssh_user', 'ssh_auth',
    'ssh_private_key', 'ssh_passphrase', 'ssh_password',
])]
#[Hidden([
    'password', 'ssl_ca', 'ssl_cert', 'ssl_key',
    'ssh_private_key', 'ssh_passphrase', 'ssh_password',
])]
class Connection extends Model
{
    /** @use HasFactory<ConnectionFactory> */
    use HasFactory;

    /**
     * Secret attributes that are never returned to the frontend and are only
     * overwritten on update when a non-empty value is submitted.
     *
     * @var list<string>
     */
    public const SECRETS = [
        'password', 'ssl_ca', 'ssl_cert', 'ssl_key',
        'ssh_private_key', 'ssh_passphrase', 'ssh_password',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'port' => 'integer',
            'password' => 'encrypted',
            'ssl_ca' => 'encrypted',
            'ssl_cert' => 'encrypted',
            'ssl_key' => 'encrypted',
            'ssh_enabled' => 'boolean',
            'ssh_port' => 'integer',
            'ssh_private_key' => 'encrypted',
            'ssh_passphrase' => 'encrypted',
            'ssh_password' => 'encrypted',
            'last_tested_at' => 'datetime',
            'last_test_ok' => 'boolean',
        ];
    }
}
