<?php

use App\Models\AgentTool;
use App\Models\AgentToolCall;
use App\Models\Condominium;
use App\Models\User;
use Database\Seeders\LookupSeeder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Laravel\Sanctum\PersonalAccessToken;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(LookupSeeder::class);
    $this->withoutVite();

    $this->condominium = Condominium::factory()->create(['name' => 'Residencial Aurora']);
    $this->superAdmin = User::factory()->superAdmin()->create();
});

test('super admin sees the integration card with tokens and the 12 agent tools', function () {
    $this->condominium->createToken('n8n produção');

    $this->actingAs($this->superAdmin)
        ->withSession(['current_condominium_id' => $this->condominium->id])
        ->get(route('settings'))
        ->assertOk()
        ->assertSee('Integração · tools do agente')
        ->assertSee('n8n produção')
        ->assertSee('Nunca usado')
        ->assertSeeInOrder(['GET', '/api/v1/residents/lookup', 'verificar_morador', '0 / 7d'])
        ->assertSeeInOrder(['DELETE', '/api/v1/reservations/{id}', 'cancelar_reserva']);

    $html = $this->get(route('settings'))->getContent();

    expect(substr_count($html, 'data-agent-tool="'))->toBe(12)
        ->and($html)->toMatch('/bg-tag-ok-bg[^"]*"[^>]*>\s*GET/')
        ->and($html)->toMatch('/bg-tag-ia-bg[^"]*"[^>]*>\s*POST/')
        ->and($html)->toMatch('/bg-tag-esc-bg[^"]*"[^>]*>\s*DELETE/');
});

test('super admin generates a token shown once in plain text while the database keeps the hash', function () {
    actingInPanel($this->superAdmin, $this->condominium);

    $component = Livewire::test('settings.integration')
        ->call('openGenerateForm')
        ->set('tokenName', 'n8n produção')
        ->call('generate')
        ->assertHasNoErrors()
        ->assertSet('showPlainTextToken', true);

    $plainTextToken = $component->get('plainTextToken');
    $token = PersonalAccessToken::sole();
    [$tokenId, $secret] = explode('|', $plainTextToken, 2);

    $component->assertSee($plainTextToken)->assertSee('Copiar');

    expect((int) $tokenId)->toBe($token->id)
        ->and($token->name)->toBe('n8n produção')
        ->and($token->token)->toBe(hash('sha256', $secret))
        ->and($token->token)->not->toContain($secret)
        ->and($token->tokenable_type)->toBe(Condominium::class)
        ->and($token->tokenable_id)->toBe($this->condominium->id);

    $component->call('dismissPlainTextToken')
        ->assertSet('plainTextToken', null)
        ->assertDontSee($plainTextToken)
        ->assertSee('n8n produção');
});

test('token name is required', function () {
    actingInPanel($this->superAdmin, $this->condominium);

    Livewire::test('settings.integration')
        ->call('openGenerateForm')
        ->set('tokenName', '')
        ->call('generate')
        ->assertHasErrors(['tokenName' => 'required']);

    expect(PersonalAccessToken::count())->toBe(0);
});

test('revoking deletes the token', function () {
    $token = $this->condominium->createToken('n8n antigo')->accessToken;
    $keptToken = $this->condominium->createToken('n8n novo')->accessToken;
    actingInPanel($this->superAdmin, $this->condominium);

    Livewire::test('settings.integration')
        ->call('revoke', $token->id)
        ->assertDontSee('n8n antigo')
        ->assertSee('n8n novo');

    expect(PersonalAccessToken::find($token->id))->toBeNull()
        ->and(PersonalAccessToken::find($keptToken->id))->not->toBeNull();
});

test('a token of another condominium cannot be revoked', function () {
    $otherToken = Condominium::factory()->create()->createToken('outro')->accessToken;
    actingInPanel($this->superAdmin, $this->condominium);

    expect(fn () => Livewire::test('settings.integration')->call('revoke', $otherToken->id))
        ->toThrow(ModelNotFoundException::class);

    expect(PersonalAccessToken::find($otherToken->id))->not->toBeNull();
});

test('sindico does not see the card and receives 403 when generating', function () {
    $sindico = User::factory()->sindico()->for($this->condominium)->create();

    $this->actingAs($sindico)
        ->get(route('settings'))
        ->assertOk()
        ->assertDontSee('Integração')
        ->assertDontSee('Gerar token');

    actingInPanel($sindico);

    Livewire::test('settings.integration')->assertForbidden();

    expect(PersonalAccessToken::count())->toBe(0);
});

test('7 day count ignores calls from 8 days ago and from another condominium', function () {
    $toolId = AgentTool::idFor(AgentTool::TICKETS_CREATE);
    $otherCondominium = Condominium::factory()->create();

    AgentToolCall::factory()->count(3)->for($this->condominium)->create(['agent_tool_id' => $toolId, 'created_at' => now()->subDays(2)]);
    AgentToolCall::factory()->for($this->condominium)->create(['agent_tool_id' => $toolId, 'created_at' => now()->subDays(8)]);
    AgentToolCall::factory()->count(2)->for($otherCondominium)->create(['agent_tool_id' => $toolId, 'created_at' => now()->subDay()]);

    actingInPanel($this->superAdmin, $this->condominium);

    $component = Livewire::test('settings.integration');

    $tools = $component->instance()->tools->keyBy('slug');

    expect($tools)->toHaveCount(12)
        ->and($tools[AgentTool::TICKETS_CREATE]->recent_calls_count)->toBe(3)
        ->and($tools[AgentTool::RULES_SEARCH]->recent_calls_count)->toBe(0);

    $component->assertSeeHtmlInOrder(['data-agent-tool="tickets_create"', '3 / 7d']);
});
