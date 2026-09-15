<?php

use App\Models\Condominium;
use App\Models\Notice;
use App\Models\User;
use Database\Seeders\LookupSeeder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(LookupSeeder::class);
    $this->withoutVite();

    $this->condominium = Condominium::factory()->create(['name' => 'Residencial Aurora']);
    $this->sindico = User::factory()->sindico()->for($this->condominium)->create();
    $this->token = $this->condominium->createToken('n8n')->plainTextToken;
});

/**
 * Call the notices API as n8n does: with the condominium token only, without the panel user signed in
 * (Sanctum would otherwise resolve the web user first). The panel user is signed back in afterwards.
 */
function fetchNoticesAsAgent(string $token): TestResponse
{
    $panelUser = Auth::user();
    Auth::forgetGuards();

    $response = test()->withToken($token)->getJson(route('api.v1.notices_list'));

    if ($panelUser !== null) {
        Auth::forgetGuards();
        test()->actingAs($panelUser);
    }

    return $response;
}

test('creating a notice stores title, text, active by default and the author without queueing jobs', function () {
    Queue::fake();
    actingInPanel($this->sindico);

    Livewire::test('pages::notices')
        ->call('create')
        ->assertSet('showForm', true)
        ->assertSet('isActive', true)
        ->set('noticeTitle', 'Obra no hall')
        ->set('noticeBody', 'Pintura do hall do bloco A na segunda-feira.')
        ->call('save')
        ->assertHasNoErrors()
        ->assertSet('showForm', false)
        ->assertDispatched('toast', type: 'success', message: 'Comunicado criado.')
        ->assertSee('Obra no hall');

    $notice = Notice::sole();

    expect($notice->condominium_id)->toBe($this->condominium->id)
        ->and($notice->title)->toBe('Obra no hall')
        ->and($notice->body)->toBe('Pintura do hall do bloco A na segunda-feira.')
        ->and($notice->is_active)->toBeTrue()
        ->and($notice->created_by_user_id)->toBe($this->sindico->id);

    Queue::assertNothingPushed();
});

test('title and text are required and the title accepts up to 255 characters', function () {
    actingInPanel($this->sindico);

    Livewire::test('pages::notices')
        ->call('create')
        ->set('noticeTitle', '')
        ->set('noticeBody', '')
        ->call('save')
        ->assertHasErrors(['noticeTitle' => 'required', 'noticeBody' => 'required'])
        ->set('noticeTitle', Str::repeat('a', 256))
        ->set('noticeBody', 'Texto')
        ->call('save')
        ->assertHasErrors(['noticeTitle' => 'max']);

    expect(Notice::count())->toBe(0);
});

test('editing updates the notice keeping its author without queueing jobs', function () {
    Queue::fake();
    $author = User::factory()->sindico()->for($this->condominium)->create();
    $notice = Notice::factory()->for($this->condominium)->create(['title' => 'Obra no hall', 'created_by_user_id' => $author->id]);
    actingInPanel($this->sindico);

    Livewire::test('pages::notices')
        ->call('edit', $notice->id)
        ->assertSet('editingId', $notice->id)
        ->assertSet('noticeTitle', 'Obra no hall')
        ->set('noticeTitle', 'Obra no hall adiada')
        ->set('noticeBody', 'A pintura foi adiada.')
        ->set('isActive', false)
        ->call('save')
        ->assertHasNoErrors()
        ->assertDispatched('toast', type: 'success', message: 'Comunicado atualizado.');

    expect($notice->fresh())
        ->title->toBe('Obra no hall adiada')
        ->body->toBe('A pintura foi adiada.')
        ->is_active->toBeFalse()
        ->created_by_user_id->toBe($author->id);

    Queue::assertNothingPushed();
});

test('creating an inactive notice keeps it out of the api', function () {
    actingInPanel($this->sindico);

    Livewire::test('pages::notices')
        ->call('create')
        ->set('noticeTitle', 'Rascunho')
        ->set('noticeBody', 'Ainda não publicado.')
        ->set('isActive', false)
        ->call('save')
        ->assertHasNoErrors();

    expect(Notice::sole()->is_active)->toBeFalse();

    fetchNoticesAsAgent($this->token)
        ->assertExactJson(['notices' => []]);
});

test('deactivating from the card removes the notice from the api on the next call and reactivating brings it back', function () {
    $notice = Notice::factory()->for($this->condominium)->create();

    fetchNoticesAsAgent($this->token)
        ->assertJsonPath('notices.*.id', [$notice->id]);

    actingInPanel($this->sindico);

    $component = Livewire::test('pages::notices')
        ->call('toggleActive', $notice->id)
        ->assertDispatched('toast', type: 'success', message: 'Comunicado desativado.')
        ->assertSeeText('Ativos · 0')
        ->assertSeeText('Inativos · 1');

    expect($notice->fresh()->is_active)->toBeFalse();

    fetchNoticesAsAgent($this->token)
        ->assertExactJson(['notices' => []]);

    $component->call('toggleActive', $notice->id);

    expect($notice->fresh()->is_active)->toBeTrue();

    fetchNoticesAsAgent($this->token)
        ->assertJsonPath('notices.*.id', [$notice->id]);
});

test('deleting asks for confirmation, removes the notice from both tabs and the api and keeps the row with deleted_at', function () {
    Queue::fake();
    $notice = Notice::factory()->for($this->condominium)->create(['title' => 'Obra no hall']);
    $inactiveNotice = Notice::factory()->inactive()->for($this->condominium)->create(['title' => 'Dedetização antiga']);
    actingInPanel($this->sindico);

    $component = Livewire::test('pages::notices');

    expect($component->html())->toContain('wire:confirm="Excluir o comunicado &quot;Obra no hall&quot;?');

    $component->call('delete', $notice->id)
        ->assertDispatched('toast', type: 'success', message: 'Comunicado excluído.')
        ->assertDontSee('Obra no hall')
        ->assertSeeText('Ativos · 0')
        ->call('selectTab', 'inativos')
        ->call('delete', $inactiveNotice->id)
        ->assertDontSee('Dedetização antiga')
        ->assertSeeText('Inativos · 0');

    expect(Notice::count())->toBe(0)
        ->and(Notice::withTrashed()->find($notice->id)->deleted_at)->not->toBeNull()
        ->and(Notice::withTrashed()->find($inactiveNotice->id)->deleted_at)->not->toBeNull();

    fetchNoticesAsAgent($this->token)
        ->assertExactJson(['notices' => []]);

    Queue::assertNothingPushed();
});

test('a notice of another condominium cannot be changed', function () {
    $otherNotice = Notice::factory()->for(Condominium::factory())->create();
    actingInPanel($this->sindico);

    expect(fn () => Livewire::test('pages::notices')->call('toggleActive', $otherNotice->id))
        ->toThrow(ModelNotFoundException::class)
        ->and(fn () => Livewire::test('pages::notices')->call('delete', $otherNotice->id))
        ->toThrow(ModelNotFoundException::class);

    expect($otherNotice->fresh())
        ->is_active->toBeTrue()
        ->deleted_at->toBeNull();
});

test('zelador receives 403', function () {
    $zelador = User::factory()->zelador()->for($this->condominium)->create();
    $notice = Notice::factory()->for($this->condominium)->create();

    $this->actingAs($zelador)
        ->get(route('notices.index'))
        ->assertForbidden();

    actingInPanel($zelador);

    Livewire::test('pages::notices')->assertForbidden();

    expect($notice->fresh())
        ->is_active->toBeTrue()
        ->deleted_at->toBeNull();
});
