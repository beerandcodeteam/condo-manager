<?php

use App\Models\AgentMedia;
use App\Models\AgentMediaKind;
use App\Models\AgentToolCall;
use App\Models\Condominium;
use App\Models\Resident;
use App\Models\Ticket;
use App\Models\TicketPhoto;
use App\Services\Integration\MediaService;
use App\Services\Tickets\TicketService;
use App\Support\Tenancy\CurrentCondominium;
use Database\Seeders\LookupSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    $this->seed(LookupSeeder::class);
    Storage::fake('local');

    $this->condominium = Condominium::factory()->create();
    $this->token = $this->condominium->createToken('n8n')->plainTextToken;
    $this->phone = '+5511999990000';
    $this->resident = Resident::factory()->for($this->condominium)->create(['phone' => $this->phone]);
});

function upload(array $overrides = []): array
{
    return array_merge(['phone' => '+5511999990000'], $overrides);
}

test('an image upload is stored and classified', function () {
    $this->withToken($this->token)
        ->post(route('api.v1.media_store'), upload([
            'file' => UploadedFile::fake()->image('vazamento.jpg'),
            'caption' => 'o cano do banheiro',
        ]), ['Accept' => 'application/json'])
        ->assertCreated()
        ->assertJsonPath('kind', 'imagem')
        ->assertJsonPath('attachable', true)
        ->assertJsonPath('caption', 'o cano do banheiro');

    $media = AgentMedia::withoutGlobalScopes()->first();

    expect($media->phone)->toBe($this->phone)
        ->and($media->resident_id)->toBe($this->resident->id)
        ->and($media->ticket_id)->toBeNull();

    Storage::disk('local')->assertExists($media->file_path);
});

test('audio is classified and carries its transcription', function () {
    $this->withToken($this->token)
        ->post(route('api.v1.media_store'), upload([
            'file' => UploadedFile::fake()->create('nota.ogg', 40, 'audio/ogg'),
            'transcription' => 'o elevador do bloco A parou de novo',
        ]), ['Accept' => 'application/json'])
        ->assertCreated()
        ->assertJsonPath('kind', 'audio')
        // Áudio não vira foto de chamado.
        ->assertJsonPath('attachable', false);

    expect(AgentMedia::withoutGlobalScopes()->value('transcription'))->toBe('o elevador do bloco A parou de novo');
});

test('an unknown type falls back to documento', function () {
    $this->withToken($this->token)
        ->post(route('api.v1.media_store'), upload([
            'file' => UploadedFile::fake()->create('convencao.pdf', 40, 'application/pdf'),
        ]), ['Accept' => 'application/json'])
        ->assertCreated()
        ->assertJsonPath('kind', 'documento')
        ->assertJsonPath('attachable', false);
});

test('a file over the limit of its kind is refused', function () {
    $overLimit = (int) config('condo.media.max_kb.imagem') + 1;

    $this->withToken($this->token)
        ->post(route('api.v1.media_store'), upload([
            'file' => UploadedFile::fake()->create('enorme.jpg', $overLimit, 'image/jpeg'),
        ]), ['Accept' => 'application/json'])
        ->assertStatus(422)
        ->assertJsonPath('code', 'validation_error');

    expect(AgentMedia::withoutGlobalScopes()->count())->toBe(0);
});

test('pending media rides along with the conversation history', function () {
    $image = AgentMedia::factory()->for($this->condominium)->create(['phone' => $this->phone]);
    AgentMedia::factory()->for($this->condominium)->audio()->create(['phone' => $this->phone]);

    $this->withToken($this->token)
        ->getJson(route('api.v1.conversations_list', ['phone' => $this->phone]))
        ->assertOk()
        ->assertJsonCount(2, 'pending_media')
        ->assertJsonPath('pending_media.0.id', $image->id)
        ->assertJsonPath('pending_media.0.attachable', true)
        ->assertJsonPath('pending_media.1.attachable', false);
});

test('opening a ticket attaches the image and takes it out of pending', function () {
    Storage::disk('local')->put('whatsapp/1/x.jpg', 'conteudo-da-foto');
    $media = AgentMedia::factory()->for($this->condominium)
        ->create(['phone' => $this->phone, 'file_path' => 'whatsapp/1/x.jpg', 'mime_type' => 'image/jpeg']);

    $this->withToken($this->token)
        ->postJson(route('api.v1.tickets_create'), [
            'phone' => $this->phone,
            'description' => 'vazamento no banheiro',
            'media_ids' => [$media->id],
        ])
        ->assertCreated();

    $ticket = Ticket::withoutGlobalScopes()->first();
    $photo = TicketPhoto::withoutGlobalScopes()->first();

    expect($photo->ticket_id)->toBe($ticket->id)
        ->and($photo->mime_type)->toBe('image/jpeg')
        ->and($media->fresh()->ticket_id)->toBe($ticket->id);

    Storage::disk('local')->assertExists($photo->file_path);
    expect(Storage::disk('local')->get($photo->file_path))->toBe('conteudo-da-foto');

    // Consumida: não aparece mais como disponível.
    $this->withToken($this->token)
        ->getJson(route('api.v1.conversations_list', ['phone' => $this->phone]))
        ->assertJsonCount(0, 'pending_media');
});

test('media of another phone, another condominium or already attached is ignored', function () {
    $other = Condominium::factory()->create();

    $fromOtherPhone = AgentMedia::factory()->for($this->condominium)->create(['phone' => '+5511888880000']);
    $fromOtherCondo = AgentMedia::factory()->for($other)->create(['phone' => $this->phone]);
    $alreadyUsed = AgentMedia::factory()->for($this->condominium)
        ->create(['phone' => $this->phone, 'ticket_id' => Ticket::factory()->for($this->condominium)->create()->id]);
    $audio = AgentMedia::factory()->for($this->condominium)->audio()->create(['phone' => $this->phone]);

    // Nenhum id é recusado com erro: o agente não pode descobrir que existem.
    $this->withToken($this->token)
        ->postJson(route('api.v1.tickets_create'), [
            'phone' => $this->phone,
            'description' => 'vazamento',
            'media_ids' => [$fromOtherPhone->id, $fromOtherCondo->id, $alreadyUsed->id, $audio->id],
        ])
        ->assertCreated();

    expect(TicketPhoto::withoutGlobalScopes()->count())->toBe(0);
});

test('more media ids than the photo limit is a validation error the agent can correct', function () {
    $limit = (int) config('condo.tickets.max_photos');
    $ids = range(1, $limit + 1);

    $this->withToken($this->token)
        ->postJson(route('api.v1.tickets_create'), [
            'phone' => $this->phone,
            'description' => 'fotos demais',
            'media_ids' => $ids,
        ])
        ->assertStatus(422)
        ->assertJsonPath('code', 'validation_error')
        ->assertJsonPath('errors.media_ids.0', "O campo mídias não deve ter mais de {$limit} itens.");
});

test('the service caps attachable media at the photo limit even past validation', function () {
    $limit = (int) config('condo.tickets.max_photos');
    $ids = [];

    foreach (range(1, $limit + 2) as $i) {
        $ids[] = AgentMedia::factory()->for($this->condominium)->create(['phone' => $this->phone])->id;
    }

    app(MediaService::class);
    app(CurrentCondominium::class)->set($this->condominium);

    expect(app(MediaService::class)->attachable($this->phone, $ids))->toHaveCount($limit);
});

test('a media upload does not record an agent tool call', function () {
    $this->withToken($this->token)
        ->post(route('api.v1.media_store'), upload([
            'file' => UploadedFile::fake()->image('x.jpg'),
        ]), ['Accept' => 'application/json'])
        ->assertCreated();

    expect(AgentToolCall::withoutGlobalScopes()->count())->toBe(0);
});

test('the kind is derived from the mime type', function () {
    expect(AgentMediaKind::slugForMime('image/webp'))->toBe('imagem')
        ->and(AgentMediaKind::slugForMime('audio/ogg; codecs=opus'))->toBe('audio')
        ->and(AgentMediaKind::slugForMime('video/mp4'))->toBe('video')
        ->and(AgentMediaKind::slugForMime('application/vnd.ms-excel'))->toBe('documento');
});

test('the ticket photo disk matches the one the service writes to', function () {
    expect(TicketService::PHOTO_DISK)->toBe(config('condo.media.disk'));
});
