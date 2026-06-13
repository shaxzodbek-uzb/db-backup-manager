<?php

namespace App\Http\Requests;

use App\Concerns\ConnectionValidationRules;
use Illuminate\Foundation\Http\FormRequest;

class UpdateConnectionRequest extends FormRequest
{
    use ConnectionValidationRules;

    protected function prepareForValidation(): void
    {
        $this->applyDatabaseUrl();
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return $this->connectionRules();
    }
}
