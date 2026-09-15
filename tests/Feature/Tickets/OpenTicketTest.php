<?php

use App\Exceptions\Api\InvalidCategory;
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
use App\Services\Tickets\TicketService;
use Database\Seeders\LookupSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    $this->seed(LookupSeeder::class);
    Storage::fake('local');
    Queue::fake();

    $this->condominium = Condominium::factory()->withWebhook()->create();
    $this->category = TicketCategory::factory()->for($this->condominium)->create(['name' => 'Elevador', 'slug' => 'elevador']);
    $this->resident = Resident::factory()->for($this->condominium)->create();
    $this->service = app(TicketService::class);
});

test('opens an aberto ticket with the initial status history entry', function () {
    $ticket = $this->service->open(
        condominium: $this->condominium,
        description: '  Elevador do bloco B parou no 3º andar  ',
        origin: TicketOrigin::WHATSAPP,
        location: 'Elevador Bloco B',
        category: 'elevador',
        priority: TicketPriority::ALTA,
        resident: $this->resident,
    );

    expect($ticket->fresh())
        ->condominium_id->toBe($this->condominium->id)
        ->protocol_number->toBe(1)
        ->ticket_status_id->toBe(TicketStatus::idFor(TicketStatus::ABERTO))
        ->ticket_priority_id->toBe(TicketPriority::idFor(TicketPriority::ALTA))
        ->ticket_origin_id->toBe(TicketOrigin::idFor(TicketOrigin::WHATSAPP))
        ->ticket_category_id->toBe($this->category->id)
        ->unit_id->toBe($this->resident->unit_id)
        ->resident_id->toBe($this->resident->id)
        ->opened_by_user_id->toBeNull()
        ->description->toBe('Elevador do bloco B parou no 3º andar')
        ->location->toBe('Elevador Bloco B');

    $statusChange = TicketStatusChange::sole();

    expect($statusChange)
        ->ticket_id->toBe($ticket->id)
        ->condominium_id->toBe($this->condominium->id)
        ->from_ticket_status_id->toBeNull()
        ->to_ticket_status_id->toBe(TicketStatus::idFor(TicketStatus::ABERTO))
        ->comment->toBeNull()
        ->user_id->toBeNull();
});

test('the initial history entry keeps the panel user who opened the ticket', function () {
    $sindico = User::factory()->sindico()->for($this->condominium)->create();

    $ticket = $this->service->open(
        condominium: $this->condominium,
        description: 'Lâmpada queimada na garagem',
        origin: TicketOrigin::PAINEL,
        user: $sindico,
    );

    expect($ticket->opened_by_user_id)->toBe($sindico->id)
        ->and($ticket->unit_id)->toBeNull()
        ->and($ticket->resident_id)->toBeNull()
        ->and(TicketStatusChange::sole()->user_id)->toBe($sindico->id);
});

test('priority defaults to media', function () {
    $ticket = $this->service->open(condominium: $this->condominium, description: 'Portão travado', origin: TicketOrigin::PAINEL);

    expect($ticket->ticket_priority_id)->toBe(TicketPriority::idFor(TicketPriority::MEDIA));
});

test('category may be given by id', function () {
    $ticket = $this->service->open(condominium: $this->condominium, description: 'Portão travado', origin: TicketOrigin::PAINEL, category: $this->category->id);

    expect($ticket->ticket_category_id)->toBe($this->category->id);
});

test('an inactive, unknown or foreign category throws invalid_category', function (string $case) {
    $category = match ($case) {
        'inactive slug' => TicketCategory::factory()->for($this->condominium)->create(['slug' => 'portaria', 'is_active' => false])->slug,
        'inactive id' => TicketCategory::factory()->for($this->condominium)->create(['is_active' => false])->id,
        'unknown slug' => 'inexistente',
        'another condominium' => TicketCategory::factory()->for(Condominium::factory())->create()->id,
    };

    $this->service->open(
        condominium: $this->condominium,
        description: 'Portão travado',
        origin: TicketOrigin::PAINEL,
        category: $category,
    );
})->with(['inactive slug', 'inactive id', 'unknown slug', 'another condominium'])->throws(InvalidCategory::class);

test('invalid category error has the invalid_category code and creates nothing', function () {
    TicketCategory::factory()->for($this->condominium)->create(['slug' => 'portaria', 'is_active' => false]);

    try {
        $this->service->open(condominium: $this->condominium, description: 'Portão travado', origin: TicketOrigin::PAINEL, category: 'portaria');
        $this->fail('InvalidCategory was not thrown.');
    } catch (InvalidCategory $exception) {
        expect($exception->errorCode)->toBe('invalid_category')
            ->and($exception->status)->toBe(422);
    }

    expect(Ticket::count())->toBe(0)
        ->and($this->condominium->fresh()->last_ticket_protocol)->toBe(0);
});

test('photos are stored on the private disk and in ticket_photos with mime type and size', function () {
    $ticket = $this->service->open(
        condominium: $this->condominium,
        description: 'Infiltração no teto',
        origin: TicketOrigin::WHATSAPP,
        resident: $this->resident,
        photos: [
            UploadedFile::fake()->image('teto.jpg', 800, 600)->size(300),
            UploadedFile::fake()->image('mancha.png')->size(120),
        ],
    );

    $photos = TicketPhoto::orderBy('id')->get();

    expect($photos)->toHaveCount(2)
        ->and($photos->pluck('ticket_id')->unique()->all())->toBe([$ticket->id])
        ->and($photos->pluck('condominium_id')->unique()->all())->toBe([$this->condominium->id])
        ->and($photos[0]->mime_type)->toBe('image/jpeg')
        ->and($photos[1]->mime_type)->toBe('image/png')
        ->and($photos[0]->size_bytes)->toBe(300 * 1024)
        ->and($photos[1]->size_bytes)->toBe(120 * 1024);

    foreach ($photos as $photo) {
        expect($photo->file_path)->toStartWith("tickets/{$this->condominium->id}/{$ticket->id}/");
        Storage::disk('local')->assertExists($photo->file_path);
    }
});

test('stored photo files are removed when the transaction fails', function () {
    TicketStatusChange::creating(fn () => throw new RuntimeException('falha simulada'));

    expect(fn () => $this->service->open(
        condominium: $this->condominium,
        description: 'Infiltração no teto',
        origin: TicketOrigin::WHATSAPP,
        resident: $this->resident,
        photos: [UploadedFile::fake()->image('teto.jpg')],
    ))->toThrow(RuntimeException::class, 'falha simulada');

    expect(Ticket::count())->toBe(0)
        ->and(TicketPhoto::count())->toBe(0)
        ->and(Storage::disk('local')->allFiles())->toBe([])
        ->and($this->condominium->fresh()->last_ticket_protocol)->toBe(0);
});

test('a photo failing after others were written removes every stored file', function () {
    TicketPhoto::created(function (TicketPhoto $photo) {
        if (TicketPhoto::count() === 2) {
            throw new RuntimeException('falha na segunda foto');
        }
    });

    expect(fn () => $this->service->open(
        condominium: $this->condominium,
        description: 'Infiltração no teto',
        origin: TicketOrigin::PAINEL,
        photos: [UploadedFile::fake()->image('a.jpg'), UploadedFile::fake()->image('b.jpg')],
    ))->toThrow(RuntimeException::class);

    expect(Storage::disk('local')->allFiles())->toBe([])
        ->and(Ticket::count())->toBe(0);
});

test('a resident of another unit raises a validation error', function () {
    $otherUnit = Unit::factory()->for($this->condominium)->create();

    try {
        $this->service->open(
            condominium: $this->condominium,
            description: 'Portão travado',
            origin: TicketOrigin::PAINEL,
            unit: $otherUnit->id,
            resident: $this->resident->id,
        );
        $this->fail('ValidationException was not thrown.');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toHaveKey('resident_id')
            ->and($exception->errors()['resident_id'][0])->toBe('O morador não pertence à unidade informada.');
    }

    expect(Ticket::count())->toBe(0);
});

test('a resident without a unit uses the resident unit', function () {
    $ticket = $this->service->open(
        condominium: $this->condominium,
        description: 'Portão travado',
        origin: TicketOrigin::PAINEL,
        resident: $this->resident->id,
    );

    expect($ticket->unit_id)->toBe($this->resident->unit_id);
});

test('unit and resident of another condominium are rejected', function (string $field) {
    $otherResident = Resident::factory()->for(Condominium::factory())->create();

    $this->service->open(
        condominium: $this->condominium,
        description: 'Portão travado',
        origin: TicketOrigin::PAINEL,
        unit: $field === 'unit' ? $otherResident->unit_id : null,
        resident: $field === 'resident' ? $otherResident->id : null,
    );
})->with(['unit', 'resident'])->throws(ValidationException::class);

test('empty description and invalid priority are rejected', function () {
    expect(fn () => $this->service->open(condominium: $this->condominium, description: '   ', origin: TicketOrigin::PAINEL))
        ->toThrow(ValidationException::class)
        ->and(fn () => $this->service->open(condominium: $this->condominium, description: 'Portão', origin: TicketOrigin::PAINEL, priority: 'urgente'))
        ->toThrow(ValidationException::class);
});

test('opening a ticket creates no webhook delivery', function () {
    $this->service->open(
        condominium: $this->condominium,
        description: 'Elevador parado',
        origin: TicketOrigin::WHATSAPP,
        resident: $this->resident,
    );

    expect(WebhookDelivery::count())->toBe(0);
    Queue::assertNothingPushed();
});
