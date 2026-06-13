<?php

namespace App\Concerns;

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
        ];
    }
}
