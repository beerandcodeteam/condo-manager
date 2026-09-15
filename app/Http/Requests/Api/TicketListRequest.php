<?php

namespace App\Http\Requests\Api;

use App\Rules\E164Phone;
use App\Services\Tickets\TicketService;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class TicketListRequest extends FormRequest
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
            'status' => ['nullable', 'string', Rule::in(TicketService::API_STATUS_FILTERS)],
        ];
    }
}
