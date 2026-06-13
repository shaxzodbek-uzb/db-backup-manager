<?php

namespace App\Concerns;

use App\Support\DatabaseUrlParser;
use Illuminate\Validation\Rule;

trait ConnectionValidationRules
{
    /**
     * Validation rules shared by the store and update requests.
     *
     * Secrets are nullable: on update a blank value means "keep the stored one".
     *
     * @return array<string, mixed>
     */
    protected function connectionRules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'driver' => ['required', Rule::in(['mysql', 'pgsql'])],
            'host' => ['required', 'string', 'max:255'],
            'port' => ['required', 'integer', 'between:1,65535'],
            'username' => ['required', 'string', 'max:255'],
            'password' => ['nullable', 'string', 'max:1024'],

            // TLS (optional; secrets are write-only — blank keeps the stored one)
            'ssl_mode' => ['nullable', Rule::in(['disable', 'allow', 'prefer', 'require', 'verify-ca', 'verify-full'])],
            'ssl_ca' => ['nullable', 'string', 'max:65535'],
            'ssl_cert' => ['nullable', 'string', 'max:65535'],
            'ssl_key' => ['nullable', 'string', 'max:65535'],

            // SSH tunnel (key auth only in v1)
            'ssh_enabled' => ['boolean'],
            'ssh_host' => ['nullable', 'required_if:ssh_enabled,true', 'string', 'max:255'],
            'ssh_port' => ['nullable', 'integer', 'between:1,65535'],
            'ssh_user' => ['nullable', 'required_if:ssh_enabled,true', 'string', 'max:255'],
            'ssh_auth' => ['nullable', Rule::in(['key'])],
            'ssh_private_key' => ['nullable', 'string', 'max:65535'],
            'ssh_passphrase' => ['nullable', 'string', 'max:1024'],
            'ssh_password' => ['nullable', 'string', 'max:1024'],
        ];
    }

    /**
     * Expand a supplied database URL into discrete connection fields before
     * validation, so a connection can be created from a single DSN string.
     */
    protected function applyDatabaseUrl(): void
    {
        $url = $this->input('url');
        if (! is_string($url) || trim($url) === '') {
            return;
        }

        $parsed = DatabaseUrlParser::parse($url);
        if ($parsed === null) {
            return;
        }

        $this->merge([
            'driver' => $parsed['driver'],
            'host' => $parsed['host'],
            'port' => $parsed['port'],
            'username' => $parsed['username'],
            'password' => $this->filled('password') ? $this->input('password') : $parsed['password'],
        ]);
    }
}
