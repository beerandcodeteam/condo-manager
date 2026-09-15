<?php

use App\Models\AgentTool;
use App\Models\AgentToolCall;
use App\Models\Condominium;
use App\Models\Resident;
use App\Models\Ticket;
use App\Models\TicketCategory;
use App\Models\TicketOrigin;
use App\Models\TicketPhoto;
use App\Models\TicketPriority;
use App\Models\TicketStatus;
use App\Models\TicketStatusChange;
use App\Models\ToolCallResult;
use App\Models\WebhookDelivery;
use Database\Seeders\LookupSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    $this->seed(LookupSeeder::class);
    Storage::fake('local');
    Queue::fake();

    $this->condominium = Condominium::factory()->withWebhook()->create();
    $this->token = $this->condominium->createToken('n8n')->plainTextToken;
    $this->resident = Resident::factory()->for($this->condominium)->create(['phone' => '+5511999990000']);
    TicketCategory::factory()->for($this->condominium)->create(['name' => 'Elevador', 'slug' => 'elevador']);
});

test('opens a ticket and responds 201 with an integer incremental protocol', function () {
    $this->travelTo('2026-09-15 13:12:00');

    $this->withToken($this->token)
        ->post(route('api.v1.tickets_create'), [
            'phone' => '+55 11 99999-0000',
            'description' => 'Elevador do bloco B parou no 3º andar',
            'location' => 'Elevador Bloco B',
            'category' => 'elevador',
            'priority' => 'alta',
        ], ['Accept' => 'application/json'])
        ->assertCreated()
        ->assertExactJson([
            'protocol' => 1,
            'status' => 'aberto',
            'priority' => 'alta',
            'created_at' => '2026-09-15T10:12:00-03:00',
        ]);

    $this->withToken($this->token)
        ->postJson(route('api.v1.tickets_create'), ['phone' => '+5511999990000', 'description' => 'Lâmpada queimada'])
        ->assertCreated()
        ->assertJsonPath('protocol', 2);

    $ticket = Ticket::query()->withoutGlobalScopes()->where('protocol_number', 1)->sole();

    expect($ticket)
        ->condominium_id->toBe($this->condominium->id)
        ->resident_id->toBe($this->resident->id)
        ->unit_id->toBe($this->resident->unit_id)
        ->ticket_origin_id->toBe(TicketOrigin::idFor(TicketOrigin::WHATSAPP))
        ->ticket_status_id->toBe(TicketStatus::idFor(TicketStatus::ABERTO))
        ->location->toBe('Elevador Bloco B')
        ->and(TicketStatusChange::query()->withoutGlobalScopes()->where('ticket_id', $ticket->id)->count())->toBe(1)
        ->and(WebhookDelivery::query()->withoutGlobalScopes()->count())->toBe(0);
});

test('priority defaults to media', function () {
    $this->withToken($this->token)
        ->postJson(route('api.v1.tickets_create'), ['phone' => '+5511999990000', 'description' => 'Portão travado'])
        ->assertCreated()
        ->assertJsonPath('priority', 'media')
        ->assertJsonPath('status', 'aberto');

    expect(Ticket::query()->withoutGlobalScopes()->sole()->ticket_priority_id)->toBe(TicketPriority::idFor(TicketPriority::MEDIA));
});

test('phone and description are required and an invalid priority responds 422', function () {
    $this->withToken($this->token)
        ->postJson(route('api.v1.tickets_create'), [])
        ->assertUnprocessable()
        ->assertJsonPath('code', 'validation_error')
        ->assertJsonValidationErrors(['phone', 'description']);

    $this->withToken($this->token)
        ->postJson(route('api.v1.tickets_create'), ['phone' => '+5511999990000', 'description' => 'Portão', 'priority' => 'urgente'])
        ->assertUnprocessable()
        ->assertJsonPath('code', 'validation_error')
        ->assertJsonValidationErrors(['priority']);

    $this->withToken($this->token)
        ->postJson(route('api.v1.tickets_create'), ['phone' => '+5511999990000', 'description' => 'Portão', 'location' => str_repeat('a', 256)])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['location']);

    expect(Ticket::query()->withoutGlobalScopes()->count())->toBe(0);
});

test('unknown resident responds 403 resident_not_found', function () {
    Resident::factory()->inactive()->for($this->condominium)->create(['phone' => '+5511988887777']);

    $this->withToken($this->token)
        ->postJson(route('api.v1.tickets_create'), ['phone' => '+5511900000000', 'description' => 'Portão travado'])
        ->assertForbidden()
        ->assertJsonPath('code', 'resident_not_found');

    $this->withToken($this->token)
        ->postJson(route('api.v1.tickets_create'), ['phone' => '+5511988887777', 'description' => 'Portão travado'])
        ->assertForbidden()
        ->assertJsonPath('code', 'resident_not_found');

    expect(Ticket::query()->withoutGlobalScopes()->count())->toBe(0);
});

test('inactive or unknown category responds 422 invalid_category', function (string $category) {
    TicketCategory::factory()->for($this->condominium)->create(['slug' => 'portaria', 'is_active' => false]);
    TicketCategory::factory()->for(Condominium::factory())->create(['slug' => 'jardinagem']);

    $this->withToken($this->token)
        ->postJson(route('api.v1.tickets_create'), ['phone' => '+5511999990000', 'description' => 'Portão travado', 'category' => $category])
        ->assertUnprocessable()
        ->assertJsonPath('code', 'invalid_category');

    expect(Ticket::query()->withoutGlobalScopes()->count())->toBe(0);
})->with(['portaria', 'inexistente', 'jardinagem']);

test('six photos respond 422', function () {
    $photos = collect(range(1, 6))->map(fn (int $index) => UploadedFile::fake()->image("foto-{$index}.jpg"))->all();

    $this->withToken($this->token)
        ->post(route('api.v1.tickets_create'), ['phone' => '+5511999990000', 'description' => 'Infiltração', 'photos' => $photos], ['Accept' => 'application/json'])
        ->assertUnprocessable()
        ->assertJsonPath('code', 'validation_error')
        ->assertJsonValidationErrors(['photos']);

    expect(Ticket::query()->withoutGlobalScopes()->count())->toBe(0);
});

test('an 11MB photo responds 422', function () {
    $this->withToken($this->token)
        ->post(route('api.v1.tickets_create'), [
            'phone' => '+5511999990000',
            'description' => 'Infiltração',
            'photos' => [UploadedFile::fake()->image('grande.jpg')->size(11 * 1024)],
        ], ['Accept' => 'application/json'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['photos.0']);

    expect(Ticket::query()->withoutGlobalScopes()->count())->toBe(0);
});

test('a GIF photo is rejected', function () {
    $this->withToken($this->token)
        ->post(route('api.v1.tickets_create'), [
            'phone' => '+5511999990000',
            'description' => 'Infiltração',
            'photos' => [UploadedFile::fake()->image('animada.gif')],
        ], ['Accept' => 'application/json'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['photos.0']);

    expect(Ticket::query()->withoutGlobalScopes()->count())->toBe(0);
});

test('photos are persisted on the private disk and in ticket_photos', function () {
    $this->withToken($this->token)
        ->post(route('api.v1.tickets_create'), [
            'phone' => '+5511999990000',
            'description' => 'Infiltração no teto do banheiro',
            'photos' => [
                UploadedFile::fake()->image('teto.jpg')->size(2048),
                UploadedFile::fake()->image('mancha.png'),
                UploadedFile::fake()->image('parede.webp'),
                UploadedFile::fake()->create('detalhe.heic', 500, 'image/heic'),
                UploadedFile::fake()->image('piso.jpeg')->size(10 * 1024),
            ],
        ], ['Accept' => 'application/json'])
        ->assertCreated();

    $ticket = Ticket::query()->withoutGlobalScopes()->sole();
    $photos = TicketPhoto::query()->withoutGlobalScopes()->get();

    expect($photos)->toHaveCount(5)
        ->and($photos->pluck('ticket_id')->unique()->all())->toBe([$ticket->id])
        ->and($photos->firstWhere('size_bytes', 2048 * 1024))->not->toBeNull();

    foreach ($photos as $photo) {
        expect($photo->file_path)->toStartWith("tickets/{$this->condominium->id}/{$ticket->id}/")
            ->and($photo->mime_type)->not->toBeEmpty();
        Storage::disk('local')->assertExists($photo->file_path);
    }
});

test('logs sucesso with entities.ticket_id and the resident', function () {
    $this->withToken($this->token)
        ->postJson(route('api.v1.tickets_create'), ['phone' => '+5511999990000', 'description' => 'Portão travado'])
        ->assertCreated();

    $ticket = Ticket::query()->withoutGlobalScopes()->sole();
    $toolCall = AgentToolCall::query()->withoutGlobalScopes()->sole();

    expect($toolCall->agent_tool_id)->toBe(AgentTool::idFor(AgentTool::TICKETS_CREATE))
        ->and($toolCall->tool_call_result_id)->toBe(ToolCallResult::idFor(ToolCallResult::SUCESSO))
        ->and($toolCall->http_status)->toBe(201)
        ->and($toolCall->resident_id)->toBe($this->resident->id)
        ->and($toolCall->entities)->toBe(['ticket_id' => $ticket->id]);
});

test('invalid category logs recusa with invalid_category', function () {
    $this->withToken($this->token)
        ->postJson(route('api.v1.tickets_create'), ['phone' => '+5511999990000', 'description' => 'Portão travado', 'category' => 'inexistente'])
        ->assertUnprocessable();

    $toolCall = AgentToolCall::query()->withoutGlobalScopes()->sole();

    expect($toolCall->tool_call_result_id)->toBe(ToolCallResult::idFor(ToolCallResult::RECUSA))
        ->and($toolCall->error_code)->toBe('invalid_category')
        ->and($toolCall->entities)->toBeNull();
});
