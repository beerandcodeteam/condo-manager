<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Refuses agent API input with text the database cannot store as sent: invalid UTF-8 byte sequences (a
 * Postgres error, so a 500) or NUL characters (silently truncated). Runs after LogToolCall so the refusal
 * is recorded as a `validation_error`.
 */
class EnsureValidTextInput
{
    public const MESSAGE = 'O campo contém caracteres inválidos (codificação UTF-8 inválida ou caractere nulo).';

    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     *
     * @throws ValidationException
     */
    public function handle(Request $request, Closure $next): Response
    {
        $invalidKeys = [
            ...$this->invalidKeys($request->query->all()),
            ...$this->invalidKeys($request->request->all()),
        ];

        if ($invalidKeys !== []) {
            throw ValidationException::withMessages(array_fill_keys($invalidKeys, self::MESSAGE));
        }

        return $next($request);
    }

    /**
     * Dot-notation keys of the string values that are not valid UTF-8 or contain a NUL character.
     *
     * @param  array<array-key, mixed>  $input
     * @return list<string>
     */
    private function invalidKeys(array $input, string $prefix = ''): array
    {
        $invalidKeys = [];

        foreach ($input as $key => $value) {
            $dotKey = mb_scrub($prefix.$key, 'UTF-8');

            if (is_array($value)) {
                $invalidKeys = [...$invalidKeys, ...$this->invalidKeys($value, $dotKey.'.')];
            } elseif (is_string($value) && (! mb_check_encoding($value, 'UTF-8') || str_contains($value, "\0"))) {
                $invalidKeys[] = $dotKey;
            }
        }

        return $invalidKeys;
    }
}
