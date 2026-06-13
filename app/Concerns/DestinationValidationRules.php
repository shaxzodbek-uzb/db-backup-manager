<?php

namespace App\Concerns;

use App\Models\Destination;
use Illuminate\Validation\Rule;

trait DestinationValidationRules
{
    /**
     * Type-conditional rules shared by the store and update requests.
     *
     * Secrets are required on create but nullable on update (blank = keep the
     * stored value).
     *
     * @return array<string, mixed>
     */
    protected function destinationRules(bool $creating): array
    {
        $s3Secret = $creating ? ['required_if:type,s3'] : [];
        $telegramSecret = $creating ? ['required_if:type,telegram'] : [];

        return [
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', Rule::in(Destination::TYPES)],

            // S3-compatible (AWS S3, DigitalOcean Spaces, MinIO, …)
            'config.region' => ['required_if:type,s3', 'nullable', 'string', 'max:255'],
            'config.bucket' => ['required_if:type,s3', 'nullable', 'string', 'max:255'],
            'config.access_key' => ['required_if:type,s3', 'nullable', 'string', 'max:255'],
            'config.secret_key' => [...$s3Secret, 'nullable', 'string', 'max:1024'],
            'config.endpoint' => ['nullable', 'string', 'max:255'],
            'config.prefix' => ['nullable', 'string', 'max:255'],
            'config.use_path_style' => ['nullable', 'boolean'],

            // Telegram channel
            'config.chat_id' => ['required_if:type,telegram', 'nullable', 'string', 'max:255'],
            'config.bot_token' => [...$telegramSecret, 'nullable', 'string', 'max:1024'],
        ];
    }
}
