<?php

namespace App\Http\Middleware;

use App\Models\AgentTool;
use App\Models\AgentToolCall;
use App\Models\Condominium;
use App\Models\ToolCallResult;
use App\Support\PhoneNumber;
use App\Support\Tenancy\CurrentCondominium;
use App\Support\ToolCallContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Writes one `agent_tool_calls` row per authenticated agent API request once the response was sent.
 * Logging never changes the response: failures are only reported.
 */
class LogToolCall
{
    public const LATENCY_ATTRIBUTE = 'tool_call_latency_ms';

    public const ROUTE_NAME_PREFIX = 'api.v1.';

    /**
     * Handle an incoming request. Only requests that passed the token middleware get here.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        app()->forgetInstance(ToolCallContext::class);

        $response = $next($request);

        $startedAt = (float) $request->server('REQUEST_TIME_FLOAT', microtime(true));
        $request->attributes->set(self::LATENCY_ATTRIBUTE, max(0, (int) round((microtime(true) - $startedAt) * 1000)));

        return $response;
    }

    /**
     * Record the tool call after the response was sent to the agent.
     */
    public function terminate(Request $request, Response $response): void
    {
        $condominium = app(CurrentCondominium::class)->get();

        if (! $request->attributes->has(self::LATENCY_ATTRIBUTE) || ! $condominium instanceof Condominium) {
            return;
        }

        try {
            $this->record($request, $response, $condominium);
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    private function record(Request $request, Response $response, Condominium $condominium): void
    {
        $toolCallContext = app(ToolCallContext::class);
        $status = $response->getStatusCode();
        $inputPhone = $request->input('phone');

        [$resultSlug, $errorCode] = match (true) {
            $status >= 200 && $status < 300 => [$toolCallContext->isEmpty() ? ToolCallResult::VAZIO : ToolCallResult::SUCESSO, null],
            $status >= 500 => [ToolCallResult::RECUSA, 'server_error'],
            default => [ToolCallResult::RECUSA, $this->errorCode($response)],
        };

        $toolCall = new AgentToolCall([
            'agent_tool_id' => AgentTool::idFor(Str::after((string) $request->route()?->getName(), self::ROUTE_NAME_PREFIX)),
            'personal_access_token_id' => $condominium->currentAccessToken()->getKey(),
            'resident_id' => $toolCallContext->resident()?->getKey(),
            'phone' => $toolCallContext->phone() ?? (is_string($inputPhone) ? PhoneNumber::normalize($inputPhone) : null),
            'tool_call_result_id' => ToolCallResult::idFor($resultSlug),
            'http_status' => $status,
            'error_code' => $errorCode,
            'entities' => $toolCallContext->entities(),
            'latency_ms' => $request->attributes->get(self::LATENCY_ATTRIBUTE),
        ]);

        $toolCall->condominium_id = $condominium->id;
        $toolCall->save();
    }

    /**
     * The stable `code` of a JSON error response.
     */
    private function errorCode(Response $response): ?string
    {
        $body = json_decode((string) $response->getContent(), true);

        return is_array($body) && is_string($body['code'] ?? null) ? $body['code'] : null;
    }
}
