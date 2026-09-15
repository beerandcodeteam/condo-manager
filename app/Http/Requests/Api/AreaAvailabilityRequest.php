<?php

namespace App\Http\Requests\Api;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class AreaAvailabilityRequest extends FormRequest
{
    /**
     * The condominium token was already checked by the `agent` middleware group.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * The date lower bound keeps it inside the Postgres date range (`date_format` accepts year 0000).
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'date' => ['required', 'string', 'date_format:Y-m-d', 'after_or_equal:0001-01-01'],
        ];
    }

    /**
     * Get custom attributes for validator errors.
     *
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'date' => 'data',
        ];
    }
}
