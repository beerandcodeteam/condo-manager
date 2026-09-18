<?php

use App\Exceptions\Api\ApiException;
use App\Http\Middleware\EnsureCondominiumToken;
use App\Http\Middleware\EnsurePlatformToken;
use App\Http\Middleware\EnsureUserIsActive;
use App\Http\Middleware\EnsureValidTextInput;
use App\Http\Middleware\LogToolCall;
use App\Http\Middleware\SetApiCondominium;
use App\Http\Middleware\SetPanelCondominium;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->redirectGuestsTo(fn () => route('login'));
        $middleware->redirectUsersTo(fn () => route('dashboard'));

        // An unencoded "+" in `?phone=+55...` is decoded as a leading space; PhoneNumber::normalize() restores it.
        $middleware->trimStrings(except: ['phone']);

        $middleware->alias([
            'panel.condominium' => SetPanelCondominium::class,
        ]);

        $middleware->group('panel', [
            'auth',
            EnsureUserIsActive::class,
        ]);

        $middleware->group('platform', [
            EnsurePlatformToken::class,
        ]);

        $middleware->group('conversation', [
            'auth:sanctum',
            EnsureCondominiumToken::class,
            SetApiCondominium::class,
            EnsureValidTextInput::class,
        ]);

        $middleware->group('agent', [
            'auth:sanctum',
            EnsureCondominiumToken::class,
            SetApiCondominium::class,
            LogToolCall::class,
            EnsureValidTextInput::class,
        ]);

        $middleware->prependToPriorityList(
            before: SubstituteBindings::class,
            prepend: EnsureUserIsActive::class,
        );

        $middleware->prependToPriorityList(
            before: SubstituteBindings::class,
            prepend: SetPanelCondominium::class,
        );

        $middleware->prependToPriorityList(
            before: SubstituteBindings::class,
            prepend: EnsureCondominiumToken::class,
        );

        $middleware->prependToPriorityList(
            before: SubstituteBindings::class,
            prepend: SetApiCondominium::class,
        );

        $middleware->prependToPriorityList(
            before: SubstituteBindings::class,
            prepend: LogToolCall::class,
        );
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        $exceptions->dontReport(ApiException::class);

        $exceptions->render(fn (ValidationException $exception, Request $request) => $request->is('api/*')
            ? response()->json([
                'code' => 'validation_error',
                'message' => 'Os dados enviados são inválidos.',
                'errors' => $exception->errors(),
            ], $exception->status)
            : null);

        $exceptions->render(fn (AuthenticationException $exception, Request $request) => $request->is('api/*')
            ? response()->json(['code' => 'unauthenticated', 'message' => 'Token de acesso ausente ou inválido.'], 401)
            : null);

        $exceptions->render(fn (NotFoundHttpException $exception, Request $request) => $request->is('api/*')
            ? response()->json(['code' => 'not_found', 'message' => 'Recurso não encontrado.'], 404)
            : null);

        $exceptions->render(function (Throwable $exception, Request $request) {
            if (! $request->is('api/*') || $exception instanceof HttpResponseException) {
                return null;
            }

            if ($exception instanceof HttpExceptionInterface && $exception->getStatusCode() < 500) {
                return response()->json(match ($exception->getStatusCode()) {
                    403 => ['code' => 'forbidden', 'message' => 'Acesso negado.'],
                    405 => ['code' => 'method_not_allowed', 'message' => 'Método não permitido para este endpoint.'],
                    default => ['code' => 'http_error', 'message' => 'Não foi possível processar a requisição.'],
                }, $exception->getStatusCode(), $exception->getHeaders());
            }

            $debug = app()->environment('local') && config('app.debug') ? [
                'exception' => $exception::class,
                'detail' => mb_scrub($exception->getMessage(), 'UTF-8'),
                'file' => $exception->getFile(),
                'line' => $exception->getLine(),
                'trace' => collect($exception->getTrace())->map(fn (array $frame) => Arr::except($frame, ['args']))->all(),
            ] : [];

            return response()->json(['code' => 'server_error', 'message' => 'Erro interno do servidor.', ...$debug], 500);
        });
    })->create();
