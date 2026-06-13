<?php

namespace App\Http\Requests;

use App\Concerns\DestinationValidationRules;
use Illuminate\Foundation\Http\FormRequest;

class StoreDestinationRequest extends FormRequest
{
    use DestinationValidationRules;

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return $this->destinationRules(creating: true);
    }
}
