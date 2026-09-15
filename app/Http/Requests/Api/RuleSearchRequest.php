<?php

namespace App\Http\Requests\Api;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class RuleSearchRequest extends FormRequest
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
            'query' => ['required', 'string'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:'.config('condo.rag.max_limit')],
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
            'query' => 'pergunta',
            'limit' => 'limite',
        ];
    }

    /**
     * Number of results requested, defaulting to `condo.rag.default_limit`.
     */
    public function resultLimit(): int
    {
        return $this->filled('limit') ? $this->integer('limit') : (int) config('condo.rag.default_limit');
    }
}
