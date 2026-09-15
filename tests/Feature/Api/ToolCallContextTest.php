<?php

use App\Models\AgentToolCall;
use App\Models\Condominium;
use App\Models\Resident;
use App\Support\ToolCallContext;
use Database\Seeders\LookupSeeder;
use Illuminate\Support\Facades\Route;

beforeEach(function () {
    $this->seed(LookupSeeder::class);

    $this->condominium = Condominium::factory()->create();
    $this->resident = Resident::factory()->for($this->condominium)->create(['phone' => '+5541998123344']);
    $this->token = $this->condominium->createToken('n8n')->plainTextToken;

    Route::middleware(['api', 'agent'])->prefix('api/v1')->name('api.v1.')->group(function () {
        Route::post('/test/context', function (ToolCallContext $toolCallContext) {
            $toolCallContext->setResident($this->resident, '+5541998123344');
            $toolCallContext->addArticles([14, 15]);
            $toolCallContext->setTicket(123);
            $toolCallContext->setReservation(301);
            $toolCallContext->setEscalation(7);

            return ['ok' => true];
        })->name('tickets_create');

        Route::get('/test/untouched', fn () => ['ok' => true])->name('tickets_list');
    });
});

test('values set by the route appear in the entities of the tool call', function () {
    $this->withToken($this->token)->postJson('/api/v1/test/context')->assertOk();

    $toolCall = AgentToolCall::query()->withoutGlobalScopes()->sole();

    expect($toolCall->entities)->toEqual([
        'article_ids' => [14, 15],
        'ticket_id' => 123,
        'reservation_id' => 301,
        'escalation_id' => 7,
    ])
        ->and($toolCall->resident_id)->toBe($this->resident->id)
        ->and($toolCall->phone)->toBe('+5541998123344');
});

test('a second request starts with an empty context', function () {
    $this->withToken($this->token)->postJson('/api/v1/test/context')->assertOk();
    $this->withToken($this->token)->getJson('/api/v1/test/untouched')->assertOk();

    $secondCall = AgentToolCall::query()->withoutGlobalScopes()->latest('id')->first();

    expect($secondCall->entities)->toBeNull()
        ->and($secondCall->resident_id)->toBeNull()
        ->and($secondCall->phone)->toBeNull()
        ->and(AgentToolCall::query()->withoutGlobalScopes()->count())->toBe(2);
});

test('entities is null when nothing was recorded', function () {
    expect((new ToolCallContext)->entities())->toBeNull();
});
