<?php

use App\Models\Condominium;
use App\Models\Resident;
use App\Models\Ticket;
use App\Models\TicketCategory;
use App\Models\TicketOrigin;
use App\Models\TicketPhoto;
use App\Models\TicketPriority;
use App\Models\TicketStatus;
use App\Models\TicketStatusChange;
use App\Models\Unit;
use App\Models\User;
use App\Models\WebhookDelivery;
use Database\Seeders\LookupSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(LookupSeeder::class);
    $this->withoutVite();
    Storage::fake('local');
    Queue::fake();

    $this->condominium = Condominium::factory()->withWebhook()->create(['name' => 'Residencial Aurora']);
    $this->sindico = User::factory()->sindico()->for($this->condominium)->create(['name' => 'Renata Moura']);
    $this->category = TicketCategory::factory()->for($this->condominium)->create(['name' => 'Elétrica', 'slug' => 'eletrica']);
});

test('the modal offers active categories, medium priority by default and residents filtered by unit', function () {
    TicketCategory::factory()->for($this->condominium)->create(['name' => 'Portaria antiga', 'is_active' => false]);
    $unit = Unit::factory()->for($this->condominium)->create(['number' => '305']);
    $otherUnit = Unit::factory()->for($this->condominium)->create(['number' => '911']);
    Resident::factory()->for($unit)->create(['name' => 'Carlos Mendes']);
    Resident::factory()->for($otherUnit)->create(['name' => 'Rafael Nunes']);
    Resident::factory()->inactive()->for($unit)->create(['name' => 'Morador Inativo']);

    actingInPanel($this->sindico);

    $component = Livewire::test('pages::tickets')
        ->assertSeeHtml('wire:click="create"')
        ->call('create')
        ->assertSet('showCreateForm', true)
        ->assertSet('newPriority', TicketPriority::MEDIA)
        ->assertSee('Novo chamado')
        ->assertSee('Elétrica')
        ->assertDontSee('Carlos Mendes')
        ->set('newUnitId', (string) $unit->id)
        ->assertSee('Carlos Mendes')
        ->assertDontSee('Rafael Nunes')
        ->assertDontSee('Morador Inativo');

    expect($component->instance()->formCategories->pluck('name')->all())->toBe(['Elétrica'])
        ->and($component->html())->toMatch('/wire:model="newCategoryId".*?Elétrica.*?<\/select>/s')
        ->not->toMatch('/wire:model="newCategoryId"[^<]*(<option[^>]*>[^<]*<\/option>\s*)*<option[^>]*>Portaria antiga/s');

    expect($component->html())->toContain('Até 5 fotos de até 10MB (jpg, png, webp ou heic).')
        ->toContain('data-file-previews');
});

test('creates a painel ticket in the same protocol sequence and opens its drawer', function () {
    Ticket::factory()->for($this->condominium)->create();
    $unit = Unit::factory()->for($this->condominium)->create();
    $resident = Resident::factory()->for($unit)->create();

    actingInPanel($this->sindico);

    Livewire::test('pages::tickets')
        ->call('create')
        ->set('newDescription', 'Lâmpada queimada garagem G2')
        ->set('newLocation', 'Garagem G2')
        ->set('newCategoryId', (string) $this->category->id)
        ->set('newPriority', TicketPriority::BAIXA)
        ->set('newUnitId', (string) $unit->id)
        ->set('newResidentId', (string) $resident->id)
        ->set('newPhotos', [UploadedFile::fake()->image('garagem.jpg')])
        ->call('saveTicket')
        ->assertHasNoErrors()
        ->assertSet('showCreateForm', false)
        ->assertSet('selectedProtocol', '2')
        ->assertSet('showDrawer', true)
        ->assertDispatched('toast', type: 'success', message: 'Chamado #2 aberto.')
        ->assertSee('Chamado aberto manualmente')
        ->assertSee('Fotos enviadas');

    $ticket = Ticket::query()->where('protocol_number', 2)->sole();

    expect($ticket)
        ->ticket_origin_id->toBe(TicketOrigin::idFor(TicketOrigin::PAINEL))
        ->ticket_status_id->toBe(TicketStatus::idFor(TicketStatus::ABERTO))
        ->ticket_priority_id->toBe(TicketPriority::idFor(TicketPriority::BAIXA))
        ->ticket_category_id->toBe($this->category->id)
        ->unit_id->toBe($unit->id)
        ->resident_id->toBe($resident->id)
        ->opened_by_user_id->toBe($this->sindico->id)
        ->location->toBe('Garagem G2')
        ->and(TicketStatusChange::query()->where('ticket_id', $ticket->id)->sole()->user_id)->toBe($this->sindico->id)
        ->and(TicketPhoto::query()->where('ticket_id', $ticket->id)->count())->toBe(1)
        ->and($this->condominium->fresh()->last_ticket_protocol)->toBe(2);
});

test('panel and api openings share the protocol sequence', function () {
    $resident = Resident::factory()->for($this->condominium)->create(['phone' => '+5511999990000']);
    $token = $this->condominium->createToken('n8n')->plainTextToken;

    actingInPanel($this->sindico);

    Livewire::test('pages::tickets')
        ->call('create')
        ->set('newDescription', 'Portão da garagem não fecha')
        ->call('saveTicket')
        ->assertHasNoErrors();

    Auth::forgetGuards();

    $this->withToken($token)
        ->postJson(route('api.v1.tickets_create'), ['phone' => '+5511999990000', 'description' => 'Elevador parado'])
        ->assertCreated()
        ->assertJsonPath('protocol', 2);

    expect(Ticket::query()->withoutGlobalScopes()->orderBy('protocol_number')->pluck('protocol_number')->all())->toBe([1, 2])
        ->and($resident->exists)->toBeTrue();
});

test('description is required and photos respect the limits', function () {
    actingInPanel($this->sindico);

    Livewire::test('pages::tickets')
        ->call('create')
        ->call('saveTicket')
        ->assertHasErrors(['newDescription' => 'required']);

    Livewire::test('pages::tickets')
        ->call('create')
        ->set('newDescription', 'Infiltração')
        ->set('newPhotos', collect(range(1, 6))->map(fn (int $index) => UploadedFile::fake()->image("foto-{$index}.jpg"))->all())
        ->call('saveTicket')
        ->assertHasErrors(['newPhotos' => 'max']);

    Livewire::test('pages::tickets')
        ->call('create')
        ->set('newDescription', 'Infiltração')
        ->set('newPhotos', [UploadedFile::fake()->image('animada.gif')])
        ->call('saveTicket')
        ->assertHasErrors(['newPhotos.0' => 'mimes']);

    Livewire::test('pages::tickets')
        ->call('create')
        ->set('newDescription', 'Infiltração')
        ->set('newPhotos', [UploadedFile::fake()->image('grande.jpg')->size(11 * 1024)])
        ->call('saveTicket')
        ->assertHasErrors('newPhotos.0');

    expect(Ticket::count())->toBe(0);
});

test('a resident outside the chosen unit raises an error', function () {
    $unit = Unit::factory()->for($this->condominium)->create();
    $residentOfAnotherUnit = Resident::factory()->for(Unit::factory()->for($this->condominium))->create();

    actingInPanel($this->sindico);

    Livewire::test('pages::tickets')
        ->call('create')
        ->set('newDescription', 'Interfone sem áudio')
        ->set('newUnitId', (string) $unit->id)
        ->set('newResidentId', (string) $residentOfAnotherUnit->id)
        ->call('saveTicket')
        ->assertHasErrors('newResidentId')
        ->assertSee('O morador não pertence à unidade informada.')
        ->assertSet('showCreateForm', true);

    expect(Ticket::count())->toBe(0);
});

test('a resident of another condominium is rejected', function () {
    $foreignResident = Resident::factory()->for(Condominium::factory())->create();

    actingInPanel($this->sindico);

    Livewire::test('pages::tickets')
        ->call('create')
        ->set('newDescription', 'Interfone sem áudio')
        ->set('newResidentId', (string) $foreignResident->id)
        ->call('saveTicket')
        ->assertHasErrors('newResidentId');

    expect(Ticket::count())->toBe(0);
});

test('an inactive category is rejected', function () {
    $inactive = TicketCategory::factory()->for($this->condominium)->create(['is_active' => false]);

    actingInPanel($this->sindico);

    Livewire::test('pages::tickets')
        ->call('create')
        ->set('newDescription', 'Portão travado')
        ->set('newCategoryId', (string) $inactive->id)
        ->call('saveTicket')
        ->assertHasErrors('newCategoryId')
        ->assertSee('Categoria inexistente ou inativa.');

    expect(Ticket::count())->toBe(0)
        ->and($this->condominium->fresh()->last_ticket_protocol)->toBe(0);
});

test('opening from the panel dispatches no webhook, even with a resident', function () {
    $resident = Resident::factory()->for($this->condominium)->create();

    actingInPanel($this->sindico);

    Livewire::test('pages::tickets')
        ->call('create')
        ->set('newDescription', 'Vazamento na torneira do salão')
        ->set('newUnitId', (string) $resident->unit_id)
        ->set('newResidentId', (string) $resident->id)
        ->call('saveTicket')
        ->assertHasNoErrors();

    expect(Ticket::count())->toBe(1)
        ->and(WebhookDelivery::count())->toBe(0);

    Queue::assertNothingPushed();
});

test('zelador can open a ticket', function () {
    $zelador = User::factory()->zelador()->for($this->condominium)->create(['name' => 'Joaquim Silva']);

    actingInPanel($zelador);

    Livewire::test('pages::tickets')
        ->call('create')
        ->set('newDescription', 'Lâmpada queimada no hall')
        ->call('saveTicket')
        ->assertHasNoErrors()
        ->assertSee('Painel · Joaquim Silva');

    expect(Ticket::sole())
        ->opened_by_user_id->toBe($zelador->id)
        ->unit_id->toBeNull()
        ->resident_id->toBeNull()
        ->ticket_priority_id->toBe(TicketPriority::idFor(TicketPriority::MEDIA));
});
