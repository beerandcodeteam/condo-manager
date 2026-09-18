<?php

namespace App\Http\Requests\Api;

use App\Models\AgentMediaKind;
use App\Rules\E164Phone;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;

class MediaStoreRequest extends FormRequest
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
            // Teto geral; o limite por tipo é conferido em withValidator, já com o MIME em mãos.
            'file' => ['required', 'file', 'max:'.$this->largestMaxKb()],
            'caption' => ['nullable', 'string', 'max:1024'],
            'transcription' => ['nullable', 'string', 'max:'.config('condo.conversations.max_content_length')],
        ];
    }

    /**
     * The biggest per-kind limit, used as the ceiling before the kind is known.
     */
    private function largestMaxKb(): int
    {
        /** @var array<string, int> $maxKb */
        $maxKb = config('condo.media.max_kb', []);

        return $maxKb === [] ? 10240 : max($maxKb);
    }

    /**
     * The size limit depends on the kind, which is only known from the uploaded file's MIME type.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $file = $this->file('file');

            if (! $file instanceof UploadedFile) {
                return;
            }

            $kind = AgentMediaKind::slugForMime($file->getMimeType() ?? $file->getClientMimeType());
            $maxKb = (int) config("condo.media.max_kb.{$kind}");

            if ($maxKb > 0 && $file->getSize() > $maxKb * 1024) {
                $validator->errors()->add('file', "O arquivo excede o limite de {$maxKb} KB para {$kind}.");
            }
        });
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'phone' => 'telefone',
            'file' => 'arquivo',
            'caption' => 'legenda',
            'transcription' => 'transcrição',
        ];
    }
}
