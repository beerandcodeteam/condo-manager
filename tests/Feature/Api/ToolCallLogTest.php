<?php

use App\Exceptions\Api\TicketNotFound;
use App\Http\Middleware\EnsureCondominiumToken;
use App\Http\Middleware\EnsurePlatformToken;
use App\Http\Middleware\EnsureValidTextInput;
use App\Http\Middleware\LogToolCall;
use App\Models\AgentTool;
use App\Models\AgentToolCall;
use App\Models\Condominium;
use App\Models\Resident;
use App\Models\ToolCallResult;
use App\Support\ToolCallContext;
use Database\Seeders\LookupSeeder;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->seed(LookupSeeder::class);

    $this->condominium = Condominium::factory()->create();
    $this->newToken = $this->condominium->createToken('n8n');
});

test('lookup of an active resident logs sucesso with resident, token and latency', function () {
    $resident = Resident::factory()->for($this->condominium)->create(['phone' => '+5541998123344']);

    $this->withToken($this->newToken->plainTextToken)
        ->getJson(route('api.v1.residents_lookup', ['phone' => '+55 41 99812-3344']))
        ->assertOk();

    $toolCall = AgentToolCall::query()->withoutGlobalScopes()->sole();

    expect($toolCall->condominium_id)->toBe($this->condominium->id)
        ->and($toolCall->agent_tool_id)->toBe(AgentTool::idFor(AgentTool::RESIDENTS_LOOKUP))
        ->and($toolCall->tool_call_result_id)->toBe(ToolCallResult::idFor(ToolCallResult::SUCESSO))
        ->and($toolCall->personal_access_token_id)->toBe($this->newToken->accessToken->id)
        ->and($toolCall->resident_id)->toBe($resident->id)
        ->and($toolCall->phone)->toBe('+5541998123344')
        ->and($toolCall->http_status)->toBe(200)
        ->and($toolCall->error_code)->toBeNull()
        ->and($toolCall->entities)->toBeNull()
        ->and($toolCall->latency_ms)->toBeGreaterThanOrEqual(0);
});

test('lookup of an unknown phone logs sucesso with the phone and without resident', function () {
    $this->withToken($this->newToken->plainTextToken)
        ->getJson(route('api.v1.residents_lookup', ['phone' => '+5541900000000']))
        ->assertOk()
        ->assertExactJson(['exists' => false]);

    $toolCall = AgentToolCall::query()->withoutGlobalScopes()->sole();

    expect($toolCall->tool_call_result_id)->toBe(ToolCallResult::idFor(ToolCallResult::SUCESSO))
        ->and($toolCall->phone)->toBe('+5541900000000')
        ->and($toolCall->resident_id)->toBeNull();
});

test('validation error logs recusa with validation_error code', function () {
    $this->withToken($this->newToken->plainTextToken)
        ->getJson(route('api.v1.residents_lookup', ['phone' => '123']))
        ->assertUnprocessable();

    $toolCall = AgentToolCall::query()->withoutGlobalScopes()->sole();

    expect($toolCall->tool_call_result_id)->toBe(ToolCallResult::idFor(ToolCallResult::RECUSA))
        ->and($toolCall->http_status)->toBe(422)
        ->and($toolCall->error_code)->toBe('validation_error');
});

test('unauthenticated request is not logged', function () {
    $this->getJson(route('api.v1.residents_lookup', ['phone' => '+5541998123344']))->assertUnauthorized();
    $this->withToken('invalid-token')->getJson(route('api.v1.residents_lookup', ['phone' => '+5541998123344']))->assertUnauthorized();

    expect(AgentToolCall::query()->withoutGlobalScopes()->count())->toBe(0);
});

test('failing to write the log keeps the 200 response', function () {
    Exceptions::fake();
    AgentToolCall::creating(fn () => throw new RuntimeException('agent_tool_calls indisponível'));

    $this->withToken($this->newToken->plainTextToken)
        ->getJson(route('api.v1.residents_lookup', ['phone' => '+5541900000000']))
        ->assertOk()
        ->assertExactJson(['exists' => false]);

    Exceptions::assertReported(RuntimeException::class);
    expect(AgentToolCall::query()->withoutGlobalScopes()->count())->toBe(0);
});

test('results follow the response: vazio when marked empty, recusa with the api code or server_error', function () {
    Route::middleware(['api', 'agent'])->prefix('api/v1')->name('api.v1.')->group(function () {
        Route::get('/test/empty', function (ToolCallContext $toolCallContext) {
            $toolCallContext->markEmpty();

            return ['notices' => []];
        })->name('notices_list');
        Route::get('/test/not-found', fn () => throw new TicketNotFound)->name('tickets_show');
        Route::get('/test/crash', fn () => throw new RuntimeException('boom'))->name('tickets_list');
    });

    $this->withToken($this->newToken->plainTextToken)->getJson('/api/v1/test/empty')->assertOk();
    $this->withToken($this->newToken->plainTextToken)->getJson('/api/v1/test/not-found')->assertNotFound();
    $this->withToken($this->newToken->plainTextToken)->getJson('/api/v1/test/crash')->assertInternalServerError();

    expect(AgentToolCall::query()->withoutGlobalScopes()->orderBy('id')->get(['agent_tool_id', 'tool_call_result_id', 'http_status', 'error_code'])->toArray())->toBe([
        ['agent_tool_id' => AgentTool::idFor(AgentTool::NOTICES_LIST), 'tool_call_result_id' => ToolCallResult::idFor(ToolCallResult::VAZIO), 'http_status' => 200, 'error_code' => null],
        ['agent_tool_id' => AgentTool::idFor(AgentTool::TICKETS_SHOW), 'tool_call_result_id' => ToolCallResult::idFor(ToolCallResult::RECUSA), 'http_status' => 404, 'error_code' => 'ticket_not_found'],
        ['agent_tool_id' => AgentTool::idFor(AgentTool::TICKETS_LIST), 'tool_call_result_id' => ToolCallResult::idFor(ToolCallResult::RECUSA), 'http_status' => 500, 'error_code' => 'server_error'],
    ]);
});

/**
 * Rotas de infraestrutura sob api.v1.* que usam o token do condomínio mas não são tools do agente.
 * Toda outra rota com token de condomínio precisa passar pelo LogToolCall.
 *
 * @var list<string>
 */
const NON_TOOL_ROUTES = ['buffer_append', 'buffer_flush', 'conversations_append', 'conversations_list', 'media_store'];

/**
 * @return Collection<int, RoutingRoute>
 */
function apiRoutesWith(string $middleware): Collection
{
    return collect(Route::getRoutes()->getRoutes())
        ->filter(fn (RoutingRoute $route) => str_starts_with((string) $route->getName(), 'api.v1.'))
        ->filter(fn (RoutingRoute $route) => in_array($middleware, Route::gatherRouteMiddleware($route), true))
        ->values();
}

test('every logged route has a matching agent tool', function () {
    $toolSlugs = AgentTool::query()->pluck('slug');
    $routes = apiRoutesWith(LogToolCall::class);

    expect($routes)->not->toBeEmpty();

    // LogToolCall resolve o tool pelo nome da rota, então uma rota logada sem tool quebraria em produção.
    $routes->each(fn (RoutingRoute $route) => expect($toolSlugs)->toContain(Str::after((string) $route->getName(), 'api.v1.')));
});

test('every route holding a condominium token either logs tool calls or is a known non-tool route', function () {
    $unlogged = apiRoutesWith(EnsureCondominiumToken::class)
        ->reject(fn (RoutingRoute $route) => in_array(LogToolCall::class, Route::gatherRouteMiddleware($route), true))
        ->map(fn (RoutingRoute $route) => Str::after((string) $route->getName(), 'api.v1.'))
        ->sort()
        ->values()
        ->all();

    // Uma tool nova registrada no grupo errado apareceria aqui em vez de passar batido.
    expect($unlogged)->toBe(NON_TOOL_ROUTES);
});

test('the conversation routes carry a condominium token but stay out of the tool call log', function () {
    foreach (['api.v1.conversations_list', 'api.v1.conversations_append'] as $name) {
        $middleware = Route::gatherRouteMiddleware(Route::getRoutes()->getByName($name));

        expect($middleware)
            ->toContain(EnsureCondominiumToken::class)
            ->toContain(EnsureValidTextInput::class)
            ->not->toContain(LogToolCall::class);
    }
});

test('the tenant resolution route is outside the agent group, so no condominium token reaches it', function () {
    $middleware = Route::gatherRouteMiddleware(Route::getRoutes()->getByName('api.v1.auth_token'));

    expect($middleware)
        ->toContain(EnsurePlatformToken::class)
        ->not->toContain(EnsureCondominiumToken::class)
        ->not->toContain(LogToolCall::class);
});
