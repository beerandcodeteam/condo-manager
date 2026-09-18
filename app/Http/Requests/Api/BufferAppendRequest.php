<?php

namespace App\Http\Requests\Api;

use App\Rules\E164Phone;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class BufferAppendRequest extends FormRequest
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
            'content' => ['required', 'string', 'min:1', 'max:'.config('condo.conversations.max_content_length')],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['phone' => 'telefone', 'content' => 'conteúdo'];
    }
}
