<?php

namespace App\Services;

use Illuminate\Contracts\Support\Arrayable;

/**
 * Outcome of a connectivity probe (database connection or backup destination).
 *
 * @implements Arrayable<string, mixed>
 */
class TestResult implements Arrayable
{
    public function __construct(
        public bool $ok,
        public ?string $error = null,
        public int $latencyMs = 0,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'ok' => $this->ok,
            'error' => $this->error,
            'latency_ms' => $this->latencyMs,
        ];
    }
}
