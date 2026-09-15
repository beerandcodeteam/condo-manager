<?php

use App\Models\Block;
use App\Models\Condominium;
use App\Models\Ticket;
use App\Models\TicketCategory;
use App\Models\TicketPhoto;
use App\Models\TicketResidentNotice;
use App\Models\TicketStatus;
use App\Models\TicketStatusChange;
use App\Models\Unit;
use App\Models\User;
use App\Services\Tickets\TicketService;
use Database\Seeders\LookupSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(LookupSeeder::class);
    $this->withoutVite();
    Storage::fake('local');
    $this->travelTo('2026-09-15 12:38:00');

    $this->condominium = Condominium::factory()->create(['name' => 'Residencial Aurora']);
    $this->sindico = User::factory()->sindico()->for($this->condominium)->create(['name' => 'Renata Moura']);
});

test('clicking a row opens the drawer with protocol, status, title and meta', function () {
    $category = TicketCategory::factory()->for($this->condominium)->create(['name' => 'Elevador']);
    $unit = Unit::factory()->for(Block::factory()->for($this->condominium)->state(['name' => 'B']))->create(['number' => '1201']);
    $description = 'Morador relata que o elevador do bloco B parou no térreo com pessoas aguardando. Segunda ocorrência na semana.';
    $ticket = Ticket::factory()->for($unit)->create(['description' => $description, 'ticket_category_id' => $category->id]);

    actingInPanel($this->sindico);

    $component = Livewire::test('pages::tickets')
        ->assertSet('showDrawer', false)
        ->call('openTicket', $ticket->protocol_number)
        ->assertSet('selectedProtocol', (string) $ticket->protocol_number)
        ->assertSet('showDrawer', true);

    expect($component->html())
        ->toContain('wire:click="openTicket('.$ticket->protocol_number.')"')
        ->toMatch('/data-ticket-drawer.*?#1<\/span>.*?bg-tag-esc-bg text-tag-esc-fg[^>]*>Aberto/s')
        ->toContain(e(Str::limit($description, 80)))
        ->toContain('1201B · Elevador · WhatsApp · agente');
});

test('the drawer opens from the chamado query string and closes clearing it', function () {
    $ticket = Ticket::factory()->for($this->condominium)->create(['description' => 'Portão da garagem não fecha']);

    $this->actingAs($this->sindico)
        ->get(route('tickets.index', ['chamado' => $ticket->protocol_number]))
        ->assertOk()
        ->assertSee('Linha do tempo')
        ->assertSee('Descrição gerada pelo agente');

    actingInPanel($this->sindico);

    Livewire::withQueryParams(['chamado' => $ticket->protocol_number])
        ->test('pages::tickets')
        ->assertSet('showDrawer', true)
        ->set('showDrawer', false)
        ->assertSet('selectedProtocol', '')
        ->assertDontSee('Linha do tempo');
});

test('an unknown or foreign protocol does not open the drawer', function () {
    $foreign = Ticket::factory()->for(Condominium::factory())->create(['protocol_number' => 42, 'description' => 'Chamado do vizinho']);

    actingInPanel($this->sindico);

    Livewire::withQueryParams(['chamado' => 42])
        ->test('pages::tickets')
        ->assertSet('showDrawer', false)
        ->assertSet('selectedProtocol', '')
        ->assertDontSee('Chamado do vizinho')
        ->call('openTicket', 999)
        ->assertSet('showDrawer', false);

    expect($foreign->exists)->toBeTrue();
});

test('the timeline interleaves status changes and resident notices in chronological order', function () {
    $this->travelTo('2026-09-10 17:02:00');
    $ticket = Ticket::factory()->for($this->condominium)->create();
    TicketStatusChange::factory()->for($ticket)->create();

    $this->travelTo('2026-09-12 13:30:00');
    TicketStatusChange::factory()->for($ticket)->create([
        'from_ticket_status_id' => TicketStatus::idFor(TicketStatus::ABERTO),
        'to_ticket_status_id' => TicketStatus::idFor(TicketStatus::EM_ANDAMENTO),
        'comment' => 'Vistoria realizada',
        'user_id' => $this->sindico->id,
    ]);

    $this->travelTo('2026-09-12 19:00:00');
    TicketResidentNotice::factory()->for($ticket)->create(['message' => 'Reparo agendado para 17/09', 'user_id' => $this->sindico->id]);

    $this->travelTo('2026-09-11 12:00:00');
    TicketResidentNotice::factory()->for($ticket)->create(['message' => 'Recebemos seu chamado', 'user_id' => $this->sindico->id]);

    $this->travelTo('2026-09-15 12:10:00');
    TicketStatusChange::factory()->for($ticket)->create([
        'from_ticket_status_id' => TicketStatus::idFor(TicketStatus::EM_ANDAMENTO),
        'to_ticket_status_id' => TicketStatus::idFor(TicketStatus::RESOLVIDO),
        'comment' => 'Reparo concluído',
        'user_id' => $this->sindico->id,
    ]);
    $this->travelTo('2026-09-15 12:38:00');

    expect(collect(app(TicketService::class)->timeline($ticket))->pluck('text')->all())->toBe([
        'Chamado aberto pelo agente',
        'Aviso ao morador: Recebemos seu chamado',
        'Em andamento · Vistoria realizada',
        'Aviso ao morador: Reparo agendado para 17/09',
        'Resolvido · Reparo concluído',
    ]);

    actingInPanel($this->sindico);

    $html = Livewire::test('pages::tickets')
        ->call('openTicket', $ticket->protocol_number)
        ->html();

    expect($html)->toMatch('/'.implode('.*?', array_map(fn (string $text) => preg_quote(e($text), '/'), [
        'Chamado aberto pelo agente', '10 set 14:02',
        'Aviso ao morador: Recebemos seu chamado', '11 set 09:00 · Renata Moura',
        'Em andamento · Vistoria realizada', '12 set 10:30 · Renata Moura',
        'Aviso ao morador: Reparo agendado para 17/09', '12 set 16:00',
        'Resolvido · Reparo concluído', 'hoje 09:10',
    ])).'/s');
});

test('a ticket opened from the panel shows the manual opening and the plain description label', function () {
    $ticket = app(TicketService::class)->open(
        condominium: $this->condominium,
        description: 'Lâmpada queimada na garagem G2',
        origin: 'painel',
        user: $this->sindico,
    );

    actingInPanel($this->sindico);

    Livewire::test('pages::tickets')
        ->call('openTicket', $ticket->protocol_number)
        ->assertSee('Chamado aberto manualmente')
        ->assertDontSee('Chamado aberto pelo agente')
        ->assertSee('Área comum · — · Painel · Renata Moura')
        ->assertSeeHtml('>Descrição</h3>')
        ->assertDontSee('Descrição gerada pelo agente');
});

test('the description label changes with the origin', function () {
    $fromWhatsapp = Ticket::factory()->for($this->condominium)->create();
    $fromPanel = Ticket::factory()->fromPanel()->for($this->condominium)->create();

    actingInPanel($this->sindico);

    Livewire::test('pages::tickets')
        ->call('openTicket', $fromWhatsapp->protocol_number)
        ->assertSeeHtml('>Descrição gerada pelo agente</h3>')
        ->call('openTicket', $fromPanel->protocol_number)
        ->assertSeeHtml('>Descrição</h3>')
        ->assertDontSeeHtml('>Descrição gerada pelo agente</h3>');
});

test('photos are shown in a two column 4:3 grid linking to the private photo route', function () {
    $ticket = app(TicketService::class)->open(
        condominium: $this->condominium,
        description: 'Infiltração no teto',
        origin: 'painel',
        photos: [UploadedFile::fake()->image('teto.jpg'), UploadedFile::fake()->image('mancha.png')],
    );
    $photos = TicketPhoto::orderBy('id')->get();

    actingInPanel($this->sindico);

    $html = Livewire::test('pages::tickets')
        ->call('openTicket', $ticket->protocol_number)
        ->assertSee('Fotos enviadas')
        ->html();

    expect($html)
        ->toContain('grid grid-cols-2 gap-2')
        ->toContain('aspect-[4/3]')
        ->toContain('href="'.route('tickets.photos.show', $photos[0]).'"')
        ->toContain('src="'.route('tickets.photos.show', $photos[1]).'"');
});

test('the photo route responds for the same condominium and 404 for another', function () {
    $ticket = app(TicketService::class)->open(
        condominium: $this->condominium,
        description: 'Infiltração no teto',
        origin: 'painel',
        photos: [UploadedFile::fake()->image('teto.jpg')],
    );
    $photo = TicketPhoto::query()->where('ticket_id', $ticket->id)->sole();

    $otherCondominium = Condominium::factory()->create();
    $otherTicket = app(TicketService::class)->open(
        condominium: $otherCondominium,
        description: 'Vazamento',
        origin: 'painel',
        photos: [UploadedFile::fake()->image('vazamento.jpg')],
    );
    $otherPhoto = TicketPhoto::query()->where('ticket_id', $otherTicket->id)->sole();

    $this->actingAs($this->sindico)
        ->get(route('tickets.photos.show', $photo))
        ->assertOk()
        ->assertHeader('Content-Type', 'image/jpeg');

    $this->actingAs($this->sindico)
        ->get(route('tickets.photos.show', $otherPhoto))
        ->assertNotFound();

    $zelador = User::factory()->zelador()->for($this->condominium)->create();

    $this->actingAs($zelador)
        ->get(route('tickets.photos.show', $photo))
        ->assertOk();

    $superAdmin = User::factory()->superAdmin()->create();

    $this->actingAs($superAdmin)
        ->withSession(['current_condominium_id' => $otherCondominium->id])
        ->get(route('tickets.photos.show', $photo))
        ->assertNotFound();
});

test('guests cannot fetch photos', function () {
    $photo = TicketPhoto::factory()->create();

    $this->get(route('tickets.photos.show', $photo))->assertRedirect(route('login'));
});
