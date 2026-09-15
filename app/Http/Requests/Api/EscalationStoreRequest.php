<?php

namespace App\Http\Requests\Api;

use App\Rules\E164Phone;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class EscalationStoreRequest extends FormRequest
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
     * `numeric` rejects a JSON boolean `ticket_protocol`, which `integer` alone accepts as 1.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'phone' => ['required', 'string', new E164Phone],
            'reason' => ['required', 'string', Rule::exists('escalation_reasons', 'slug')],
            'summary' => ['required', 'string'],
            'ticket_protocol' => ['nullable', 'numeric', 'integer'],
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
            'reason' => 'motivo',
            'summary' => 'resumo',
            'ticket_protocol' => 'protocolo do chamado',
        ];
    }
}
