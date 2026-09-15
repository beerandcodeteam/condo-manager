<?php

use App\Models\Condominium;
use App\Models\Escalation;
use App\Models\User;
use Database\Seeders\LookupSeeder;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(LookupSeeder::class);
    $this->withoutVite();
    Queue::fake();

    $this->condominium = Condominium::factory()->create(['name' => 'Residencial Aurora']);
    $this->sindico = User::factory()->sindico()->for($this->condominium)->create();
});

test('badge counts only pendente escalations of the current condominium', function () {
    Escalation::factory()->count(2)->pendente()->for($this->condominium)->create();
    Escalation::factory()->emAtendimento()->for($this->condominium)->create();
    Escalation::factory()->resolvido()->for($this->condominium)->create();
    Escalation::factory()->count(3)->pendente()->for(Condominium::factory())->create();

    $this->actingAs($this->sindico)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee('data-escalation-badge', false);

    $badge = escalationBadgeHtml($this->actingAs($this->sindico)->get(route('tickets.index'))->getContent());

    expect($badge)->toMatch('/<span class="[^"]*bg-accent[^"]*">\s*2\s*<\/span>/');
});

test('badge is not rendered with zero pending escalations', function () {
    Escalation::factory()->emAtendimento()->for($this->condominium)->create();
    Escalation::factory()->pendente()->for(Condominium::factory())->create();

    $html = $this->actingAs($this->sindico)->get(route('dashboard'))->getContent();

    expect($html)->toContain('data-escalation-badge')
        ->and(escalationBadgeHtml($html))->not->toContain('<span')
        ->not->toMatch('/\d/');
});

test('badge is refreshed when the queue dispatches escalations-updated', function () {
    $escalation = Escalation::factory()->pendente()->for($this->condominium)->create();
    Escalation::factory()->pendente()->for($this->condominium)->create();

    actingInPanel($this->sindico);

    $badge = Livewire::test('escalation-badge');

    expect($badge->html())->toMatch('/>\s*2\s*<\/span>/');

    Livewire::test('pages::escalations')->call('assign', $escalation->id);

    $badge->dispatch('escalations-updated');

    expect($badge->html())->toMatch('/>\s*1\s*<\/span>/');
});

/**
 * Inner HTML of the escalations badge component in the sidebar item, without Livewire block markers.
 */
function escalationBadgeHtml(string $html): string
{
    preg_match('/data-nav-item="escalations".*?data-escalation-badge>(.*?)<\/span>\s*<\/a>/s', $html, $matches);

    return trim(preg_replace('/<!--.*?-->/s', '', $matches[1] ?? '<span>badge component not found</span>'));
}
