<?php

namespace App\Http\Requests\Api;

use App\Rules\E164Phone;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class ReservationStoreRequest extends FormRequest
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
     * `numeric` rejects JSON boolean ids, which `integer` alone accepts as 1; the date lower bound keeps it inside
     * the Postgres date range.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'phone' => ['required', 'string', new E164Phone],
            'area_id' => ['required', 'numeric', 'integer', 'min:1'],
            'slot_id' => ['required', 'numeric', 'integer', 'min:1'],
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
            'phone' => 'telefone',
            'area_id' => 'área',
            'slot_id' => 'faixa',
            'date' => 'data',
        ];
    }
}
