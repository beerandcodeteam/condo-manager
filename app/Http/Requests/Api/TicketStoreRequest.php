<?php

namespace App\Http\Requests\Api;

use App\Models\TicketPriority;
use App\Rules\E164Phone;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class TicketStoreRequest extends FormRequest
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
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'phone' => ['required', 'string', new E164Phone],
            'description' => ['required', 'string'],
            'location' => ['nullable', 'string', 'max:255'],
            'category' => ['nullable', 'string', 'max:255'],
            'priority' => ['nullable', 'string', Rule::in([TicketPriority::ALTA, TicketPriority::MEDIA, TicketPriority::BAIXA])],
            'photos' => ['nullable', 'array', 'max:'.config('condo.tickets.max_photos')],
            'photos.*' => ['file', 'mimes:'.implode(',', config('condo.tickets.photo_mimes')), 'max:'.config('condo.tickets.max_photo_kb')],
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
            'description' => 'descrição',
            'location' => 'local',
            'category' => 'categoria',
            'priority' => 'prioridade',
            'photos' => 'fotos',
            'photos.*' => 'foto',
        ];
    }
}
