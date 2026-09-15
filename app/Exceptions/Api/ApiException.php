<?php

namespace App\Exceptions\Api;

use Exception;
use Illuminate\Http\JsonResponse;

/**
 * Domain error of the agent API, rendered as `{code, message, ...extras}` with a stable code and a pt-BR message.
 */
class ApiException extends Exception
{
    /**
     * @param  array<string, mixed>  $extras
     */
    public function __construct(
        public readonly int $status,
        public readonly string $errorCode,
        string $message,
        public readonly array $extras = [],
    ) {
        parent::__construct($message);
    }

    public function render(): JsonResponse
    {
        return response()->json([
            'code' => $this->errorCode,
            'message' => $this->getMessage(),
            ...$this->extras,
        ], $this->status);
    }
}
