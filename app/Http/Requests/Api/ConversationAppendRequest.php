<?php

namespace App\Http\Requests\Api;

use App\Models\AgentMessageRole;
use App\Rules\E164Phone;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ConversationAppendRequest extends FormRequest
{
    /**
     * The condominium token was already checked by the `conversation` middleware group.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'phone' => ['required', 'string', new E164Phone],
            'messages' => ['required', 'array', 'min:1', 'max:'.config('condo.conversations.max_messages_per_request')],
            'messages.*.role' => ['required', 'string', Rule::in([AgentMessageRole::MORADOR, AgentMessageRole::AGENTE])],
            'messages.*.content' => ['required', 'string', 'min:1', 'max:'.config('condo.conversations.max_content_length')],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'messages' => 'mensagens',
            'messages.*.role' => 'papel',
            'messages.*.content' => 'conteúdo',
        ];
    }
}
