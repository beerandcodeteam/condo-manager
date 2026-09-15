<?php

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * @return array<string, string>
 */
function indexDefinitions(string $table): array
{
    return collect(DB::select('select indexname, indexdef from pg_indexes where schemaname = ? and tablename = ?', ['public', $table]))
        ->mapWithKeys(fn (object $index): array => [$index->indexname => $index->indexdef])
        ->all();
}

/**
 * Asserts the insert violates a unique index, inside a savepoint so the test transaction survives.
 */
function expectUniqueViolation(Closure $insert): void
{
    expect(fn () => DB::transaction($insert))->toThrow(UniqueConstraintViolationException::class);
}

function createCondominium(string $name = 'Residencial Jardins'): int
{
    return DB::table('condominiums')->insertGetId(['name' => $name]);
}

/**
 * @return array<string, array{references: string, on_delete: string}>
 */
function foreignKeys(string $table): array
{
    return collect(Schema::getForeignKeys($table))
        ->mapWithKeys(fn (array $foreignKey): array => [
            $foreignKey['columns'][0] => [
                'references' => $foreignKey['foreign_table'],
                'on_delete' => strtolower((string) $foreignKey['on_delete']),
            ],
        ])
        ->all();
}

test('lookup tables are created with name, unique slug and timestamps', function (string $lookupTable) {
    expect(Schema::hasColumns($lookupTable, ['id', 'name', 'slug', 'created_at', 'updated_at']))->toBeTrue()
        ->and(Schema::hasIndex($lookupTable, ['slug'], 'unique'))->toBeTrue();
})->with([
    'roles',
    'resident_profiles',
    'ticket_priorities',
    'reservation_origins',
    'escalation_reasons',
    'tool_call_results',
    'agent_tools',
    'document_types',
    'document_statuses',
    'ticket_statuses',
    'ticket_origins',
    'reservation_statuses',
    'reservation_cancellation_origins',
    'escalation_statuses',
    'webhook_events',
    'webhook_delivery_statuses',
]);

test('ticket statuses have is_final defaulting to false', function () {
    $isFinal = collect(Schema::getColumns('ticket_statuses'))->firstWhere('name', 'is_final');

    expect($isFinal['type_name'])->toBe('bool')
        ->and($isFinal['nullable'])->toBeFalse()
        ->and($isFinal['default'])->toBe('false');
});

test('agent tools store http method, route and description', function () {
    $columns = collect(Schema::getColumns('agent_tools'))->keyBy('name');

    expect($columns['http_method']['type'])->toBe('character varying(10)')
        ->and($columns['http_method']['nullable'])->toBeFalse()
        ->and($columns['route']['nullable'])->toBeFalse()
        ->and($columns['description']['nullable'])->toBeFalse();
});

test('condominiums table has unique name, optional info columns and protocol counter', function () {
    $columns = collect(Schema::getColumns('condominiums'))->keyBy('name');

    expect(Schema::hasIndex('condominiums', ['name'], 'unique'))->toBeTrue()
        ->and($columns['whatsapp_number']['type'])->toBe('character varying(20)')
        ->and($columns['caretaker_phone']['type'])->toBe('character varying(20)')
        ->and($columns['quiet_hours_start']['type_name'])->toBe('time')
        ->and($columns['webhook_secret']['type_name'])->toBe('text')
        ->and($columns['last_ticket_protocol']['nullable'])->toBeFalse()
        ->and($columns['last_ticket_protocol']['default'])->toBe('0');

    foreach (['city', 'whatsapp_number', 'caretaker_name', 'caretaker_phone', 'quiet_hours_start', 'quiet_hours_end', 'webhook_url', 'webhook_secret'] as $column) {
        expect($columns[$column]['nullable'])->toBeTrue();
    }
});

test('users table references role and optional condominium', function () {
    $columns = collect(Schema::getColumns('users'))->keyBy('name');

    expect($columns['role_id']['nullable'])->toBeFalse()
        ->and($columns['condominium_id']['nullable'])->toBeTrue()
        ->and($columns['is_active']['default'])->toBe('true')
        ->and(foreignKeys('users'))->toMatchArray([
            'role_id' => ['references' => 'roles', 'on_delete' => 'no action'],
            'condominium_id' => ['references' => 'condominiums', 'on_delete' => 'no action'],
        ])
        ->and(Schema::hasIndex('users', ['condominium_id', 'role_id']))->toBeTrue();
});

test('blocks are unique by name within a condominium', function () {
    $condominiumId = createCondominium();

    DB::table('blocks')->insert(['condominium_id' => $condominiumId, 'name' => 'A']);

    expectUniqueViolation(fn () => DB::table('blocks')->insert(['condominium_id' => $condominiumId, 'name' => 'A']));
});

test('unit numbers are unique per block and per condominium when unit has no block', function () {
    $condominiumId = createCondominium();
    $blockA = DB::table('blocks')->insertGetId(['condominium_id' => $condominiumId, 'name' => 'A']);
    $blockB = DB::table('blocks')->insertGetId(['condominium_id' => $condominiumId, 'name' => 'B']);

    DB::table('units')->insert([
        ['condominium_id' => $condominiumId, 'block_id' => $blockA, 'number' => '101'],
        ['condominium_id' => $condominiumId, 'block_id' => $blockB, 'number' => '101'],
        ['condominium_id' => $condominiumId, 'block_id' => null, 'number' => '101'],
    ]);

    expect(indexDefinitions('units')['units_block_id_number_unique'])->toContain('WHERE (block_id IS NOT NULL)')
        ->and(indexDefinitions('units')['units_condominium_id_number_unique'])->toContain('WHERE (block_id IS NULL)');

    expectUniqueViolation(fn () => DB::table('units')->insert(['condominium_id' => $condominiumId, 'block_id' => $blockA, 'number' => '101']));
    expectUniqueViolation(fn () => DB::table('units')->insert(['condominium_id' => $condominiumId, 'block_id' => null, 'number' => '101']));
});

test('resident phone is unique within a condominium but repeatable across condominiums', function () {
    $condominiumId = createCondominium();
    $otherCondominiumId = createCondominium('Edifício Aurora');
    $profileId = DB::table('resident_profiles')->insertGetId(['name' => 'Proprietário', 'slug' => 'proprietario']);
    $unitId = DB::table('units')->insertGetId(['condominium_id' => $condominiumId, 'number' => '101']);
    $otherUnitId = DB::table('units')->insertGetId(['condominium_id' => $otherCondominiumId, 'number' => '101']);

    $resident = fn (int $condominium, int $unit): array => [
        'condominium_id' => $condominium,
        'unit_id' => $unit,
        'resident_profile_id' => $profileId,
        'name' => 'Ana',
        'phone' => '+5511999990000',
    ];

    DB::table('residents')->insert($resident($condominiumId, $unitId));
    DB::table('residents')->insert($resident($otherCondominiumId, $otherUnitId));

    $columns = collect(Schema::getColumns('residents'))->keyBy('name');

    expect($columns['phone']['type'])->toBe('character varying(20)')
        ->and($columns['phone']['nullable'])->toBeFalse()
        ->and($columns['is_active']['default'])->toBe('true')
        ->and(Schema::hasIndex('residents', ['unit_id']))->toBeTrue()
        ->and(foreignKeys('residents'))->toMatchArray([
            'unit_id' => ['references' => 'units', 'on_delete' => 'no action'],
            'resident_profile_id' => ['references' => 'resident_profiles', 'on_delete' => 'no action'],
        ]);

    expectUniqueViolation(fn () => DB::table('residents')->insert($resident($condominiumId, $unitId)));
});

test('vector extension is enabled', function () {
    expect(DB::scalar("select extversion from pg_extension where extname = 'vector'"))->toBe('0.8.2');
});

test('rule documents reference type, status and uploader', function () {
    $columns = collect(Schema::getColumns('rule_documents'))->keyBy('name');

    expect($columns['processing_error']['type_name'])->toBe('text')
        ->and($columns['processing_error']['nullable'])->toBeTrue()
        ->and($columns['uploaded_by_user_id']['nullable'])->toBeFalse()
        ->and($columns['published_by_user_id']['nullable'])->toBeTrue()
        ->and($columns['published_at']['nullable'])->toBeTrue()
        ->and(foreignKeys('rule_documents'))->toMatchArray([
            'condominium_id' => ['references' => 'condominiums', 'on_delete' => 'no action'],
            'document_type_id' => ['references' => 'document_types', 'on_delete' => 'no action'],
            'document_status_id' => ['references' => 'document_statuses', 'on_delete' => 'no action'],
            'uploaded_by_user_id' => ['references' => 'users', 'on_delete' => 'no action'],
            'published_by_user_id' => ['references' => 'users', 'on_delete' => 'no action'],
        ])
        ->and(Schema::hasIndex('rule_documents', ['condominium_id', 'document_type_id', 'document_status_id']))->toBeTrue();
});

test('rule articles have a nullable 1536-dimension embedding with HNSW cosine index', function () {
    $columns = collect(Schema::getColumns('rule_articles'))->keyBy('name');

    expect($columns['embedding']['type'])->toBe('vector(1536)')
        ->and($columns['embedding']['nullable'])->toBeTrue()
        ->and($columns['embedded_at']['nullable'])->toBeTrue()
        ->and($columns['body']['type_name'])->toBe('text')
        ->and(indexDefinitions('rule_articles')['rule_articles_embedding_vectorindex'])->toContain('USING hnsw (embedding vector_cosine_ops)')
        ->and(Schema::hasIndex('rule_articles', ['rule_document_id', 'position']))->toBeTrue();
});

test('notices are soft deletable without embedding or validity dates', function () {
    expect(Schema::hasColumns('notices', ['title', 'body', 'is_active', 'created_by_user_id', 'deleted_at']))->toBeTrue()
        ->and(Schema::getColumnListing('notices'))->toEqualCanonicalizing([
            'id', 'condominium_id', 'title', 'body', 'is_active', 'created_by_user_id', 'created_at', 'updated_at', 'deleted_at',
        ])
        ->and(Schema::hasIndex('notices', ['condominium_id', 'is_active', 'updated_at']))->toBeTrue();
});

test('webhook deliveries store polymorphic subject, jsonb payload and attempt tracking', function () {
    $columns = collect(Schema::getColumns('webhook_deliveries'))->keyBy('name');

    expect($columns['subject_type']['nullable'])->toBeFalse()
        ->and($columns['subject_id']['nullable'])->toBeFalse()
        ->and($columns['resident_phone']['type'])->toBe('character varying(20)')
        ->and($columns['resident_phone']['nullable'])->toBeTrue()
        ->and($columns['payload']['type_name'])->toBe('jsonb')
        ->and($columns['payload']['nullable'])->toBeFalse()
        ->and($columns['attempts']['default'])->toBe('0')
        ->and($columns['last_response_code']['nullable'])->toBeTrue()
        ->and($columns['delivered_at']['nullable'])->toBeTrue()
        ->and($columns['failed_at']['nullable'])->toBeTrue()
        ->and(Schema::hasIndex('webhook_deliveries', ['subject_type', 'subject_id']))->toBeTrue()
        ->and(Schema::hasIndex('webhook_deliveries', ['condominium_id', 'webhook_delivery_status_id', 'created_at']))->toBeTrue();
});

test('agent tool calls keep the log when the token is revoked', function () {
    $columns = collect(Schema::getColumns('agent_tool_calls'))->keyBy('name');

    expect($columns['http_status']['type_name'])->toBe('int2')
        ->and($columns['entities']['type_name'])->toBe('jsonb')
        ->and($columns['entities']['nullable'])->toBeTrue()
        ->and($columns['phone']['type'])->toBe('character varying(20)')
        ->and($columns['latency_ms']['nullable'])->toBeFalse()
        ->and(foreignKeys('agent_tool_calls'))->toMatchArray([
            'agent_tool_id' => ['references' => 'agent_tools', 'on_delete' => 'no action'],
            'personal_access_token_id' => ['references' => 'personal_access_tokens', 'on_delete' => 'set null'],
            'resident_id' => ['references' => 'residents', 'on_delete' => 'no action'],
            'tool_call_result_id' => ['references' => 'tool_call_results', 'on_delete' => 'no action'],
        ])
        ->and(Schema::hasIndex('agent_tool_calls', ['condominium_id', 'created_at']))->toBeTrue()
        ->and(Schema::hasIndex('agent_tool_calls', ['condominium_id', 'agent_tool_id', 'created_at']))->toBeTrue()
        ->and(Schema::hasIndex('agent_tool_calls', ['resident_id', 'created_at']))->toBeTrue()
        ->and(indexDefinitions('agent_tool_calls')['agent_tool_calls_entities_gin_index'])->toContain('USING gin (entities jsonb_path_ops)');

    $condominiumId = createCondominium();
    $tokenId = DB::table('personal_access_tokens')->insertGetId([
        'tokenable_type' => 'App\Models\Condominium',
        'tokenable_id' => $condominiumId,
        'name' => 'n8n',
        'token' => hash('sha256', 'secret'),
    ]);
    $toolCallId = DB::table('agent_tool_calls')->insertGetId([
        'condominium_id' => $condominiumId,
        'agent_tool_id' => DB::table('agent_tools')->insertGetId([
            'name' => 'consultar_regimento', 'slug' => 'rules_search', 'http_method' => 'POST', 'route' => '/api/v1/rules/search', 'description' => 'Busca no regimento',
        ]),
        'personal_access_token_id' => $tokenId,
        'tool_call_result_id' => DB::table('tool_call_results')->insertGetId(['name' => 'Sucesso', 'slug' => 'sucesso']),
        'http_status' => 200,
        'entities' => json_encode(['article_ids' => [7, 9]]),
        'latency_ms' => 120,
    ]);

    DB::table('personal_access_tokens')->where('id', $tokenId)->delete();

    expect(DB::table('agent_tool_calls')->where('id', $toolCallId)->value('personal_access_token_id'))->toBeNull()
        ->and(DB::table('agent_tool_calls')->whereRaw("entities -> 'article_ids' @> '[9]'")->count())->toBe(1);
});

test('ticket categories are unique by slug and name within a condominium', function () {
    $condominiumId = createCondominium();
    $otherCondominiumId = createCondominium('Edifício Aurora');

    DB::table('ticket_categories')->insert([
        ['condominium_id' => $condominiumId, 'name' => 'Elétrica', 'slug' => 'eletrica'],
        ['condominium_id' => $otherCondominiumId, 'name' => 'Elétrica', 'slug' => 'eletrica'],
    ]);

    expect(collect(Schema::getColumns('ticket_categories'))->firstWhere('name', 'is_active')['default'])->toBe('true');

    expectUniqueViolation(fn () => DB::table('ticket_categories')->insert(['condominium_id' => $condominiumId, 'name' => 'Outra', 'slug' => 'eletrica']));
    expectUniqueViolation(fn () => DB::table('ticket_categories')->insert(['condominium_id' => $condominiumId, 'name' => 'Elétrica', 'slug' => 'outra']));
});

test('tickets have required lookups, optional links and unique protocol per condominium', function () {
    $columns = collect(Schema::getColumns('tickets'))->keyBy('name');

    foreach (['protocol_number', 'ticket_status_id', 'ticket_priority_id', 'ticket_origin_id', 'description'] as $column) {
        expect($columns[$column]['nullable'])->toBeFalse();
    }

    foreach (['ticket_category_id', 'unit_id', 'resident_id', 'opened_by_user_id', 'location'] as $column) {
        expect($columns[$column]['nullable'])->toBeTrue();
    }

    expect($columns['protocol_number']['type_name'])->toBe('int4')
        ->and($columns['description']['type_name'])->toBe('text')
        ->and(Schema::hasIndex('tickets', ['condominium_id', 'protocol_number'], 'unique'))->toBeTrue()
        ->and(Schema::hasIndex('tickets', ['condominium_id', 'ticket_status_id', 'ticket_priority_id']))->toBeTrue()
        ->and(Schema::hasIndex('tickets', ['unit_id', 'created_at']))->toBeTrue()
        ->and(foreignKeys('tickets'))->toMatchArray([
            'ticket_status_id' => ['references' => 'ticket_statuses', 'on_delete' => 'no action'],
            'ticket_priority_id' => ['references' => 'ticket_priorities', 'on_delete' => 'no action'],
            'ticket_origin_id' => ['references' => 'ticket_origins', 'on_delete' => 'no action'],
            'ticket_category_id' => ['references' => 'ticket_categories', 'on_delete' => 'no action'],
            'unit_id' => ['references' => 'units', 'on_delete' => 'no action'],
            'resident_id' => ['references' => 'residents', 'on_delete' => 'no action'],
            'opened_by_user_id' => ['references' => 'users', 'on_delete' => 'no action'],
        ]);
});

test('ticket photos, status changes and resident notices belong to a ticket', function () {
    $statusChanges = collect(Schema::getColumns('ticket_status_changes'))->keyBy('name');
    $notices = collect(Schema::getColumns('ticket_resident_notices'))->keyBy('name');

    expect(Schema::hasColumns('ticket_photos', ['condominium_id', 'ticket_id', 'file_path', 'mime_type', 'size_bytes']))->toBeTrue()
        ->and(foreignKeys('ticket_photos')['ticket_id'])->toBe(['references' => 'tickets', 'on_delete' => 'no action'])
        ->and($statusChanges['from_ticket_status_id']['nullable'])->toBeTrue()
        ->and($statusChanges['to_ticket_status_id']['nullable'])->toBeFalse()
        ->and($statusChanges['comment']['nullable'])->toBeTrue()
        ->and($statusChanges['user_id']['nullable'])->toBeTrue()
        ->and(Schema::hasIndex('ticket_status_changes', ['ticket_id', 'created_at']))->toBeTrue()
        ->and($notices['user_id']['nullable'])->toBeFalse()
        ->and($notices['message']['type_name'])->toBe('text')
        ->and($notices['webhook_delivery_id']['nullable'])->toBeTrue()
        ->and(foreignKeys('ticket_resident_notices')['webhook_delivery_id'])->toBe(['references' => 'webhook_deliveries', 'on_delete' => 'no action'])
        ->and(Schema::hasIndex('ticket_resident_notices', ['ticket_id', 'created_at']))->toBeTrue();
});

test('common areas have unique name per condominium and default booking rules', function () {
    $columns = collect(Schema::getColumns('common_areas'))->keyBy('name');

    expect($columns['description']['nullable'])->toBeTrue()
        ->and($columns['is_active']['default'])->toBe('true')
        ->and($columns['min_advance_hours']['default'])->toBe('24')
        ->and($columns['max_advance_days']['default'])->toBe('60')
        ->and($columns['cancellation_deadline_hours']['default'])->toBe('24')
        ->and(Schema::hasIndex('common_areas', ['condominium_id', 'name'], 'unique'))->toBeTrue();
});

test('common area slots are soft deletable time ranges', function () {
    $columns = collect(Schema::getColumns('common_area_slots'))->keyBy('name');

    expect($columns['starts_at']['type_name'])->toBe('time')
        ->and($columns['ends_at']['type_name'])->toBe('time')
        ->and($columns['deleted_at']['nullable'])->toBeTrue()
        ->and(Schema::hasIndex('common_area_slots', ['common_area_id', 'starts_at']))->toBeTrue();
});

test('reservations allow a single active reservation per slot and date', function () {
    $condominiumId = createCondominium();
    $areaId = DB::table('common_areas')->insertGetId(['condominium_id' => $condominiumId, 'name' => 'Salão de festas']);
    $slotId = DB::table('common_area_slots')->insertGetId([
        'condominium_id' => $condominiumId, 'common_area_id' => $areaId, 'starts_at' => '10:00', 'ends_at' => '16:00',
    ]);
    $unitId = DB::table('units')->insertGetId(['condominium_id' => $condominiumId, 'number' => '101']);
    $residentId = DB::table('residents')->insertGetId([
        'condominium_id' => $condominiumId,
        'unit_id' => $unitId,
        'resident_profile_id' => DB::table('resident_profiles')->insertGetId(['name' => 'Inquilino', 'slug' => 'inquilino']),
        'name' => 'Bruno',
        'phone' => '+5541998123344',
    ]);

    $reservation = [
        'condominium_id' => $condominiumId,
        'common_area_id' => $areaId,
        'common_area_slot_id' => $slotId,
        'unit_id' => $unitId,
        'resident_id' => $residentId,
        'reservation_status_id' => DB::table('reservation_statuses')->insertGetId(['name' => 'Confirmada', 'slug' => 'confirmada']),
        'reservation_origin_id' => DB::table('reservation_origins')->insertGetId(['name' => 'WhatsApp', 'slug' => 'whatsapp']),
        'date' => '2026-10-10',
        'starts_at' => '10:00',
        'ends_at' => '16:00',
    ];

    $cancelledReservationId = DB::table('reservations')->insertGetId($reservation);

    expectUniqueViolation(fn () => DB::table('reservations')->insert($reservation));

    DB::table('reservations')->where('id', $cancelledReservationId)->update(['cancelled_at' => now()]);
    DB::table('reservations')->insert($reservation);

    $columns = collect(Schema::getColumns('reservations'))->keyBy('name');

    foreach (['created_by_user_id', 'cancelled_at', 'reservation_cancellation_origin_id', 'cancellation_reason', 'cancelled_by_user_id'] as $column) {
        expect($columns[$column]['nullable'])->toBeTrue();
    }

    expect(DB::table('reservations')->where('common_area_slot_id', $slotId)->count())->toBe(2)
        ->and($columns['date']['type_name'])->toBe('date')
        ->and($columns['starts_at']['type_name'])->toBe('time')
        ->and(indexDefinitions('reservations')['reservations_common_area_slot_id_date_unique'])->toContain('WHERE (cancelled_at IS NULL)')
        ->and(Schema::hasIndex('reservations', ['unit_id', 'date']))->toBeTrue()
        ->and(Schema::hasIndex('reservations', ['common_area_id', 'date']))->toBeTrue();
});

test('escalations track status, reason, assignee and response', function () {
    $columns = collect(Schema::getColumns('escalations'))->keyBy('name');

    foreach (['resident_id', 'unit_id', 'escalation_status_id', 'escalation_reason_id', 'summary'] as $column) {
        expect($columns[$column]['nullable'])->toBeFalse();
    }

    foreach (['ticket_id', 'assigned_user_id', 'assigned_at', 'response', 'responded_by_user_id', 'resolved_at'] as $column) {
        expect($columns[$column]['nullable'])->toBeTrue();
    }

    expect(Schema::hasIndex('escalations', ['condominium_id', 'escalation_status_id', 'created_at']))->toBeTrue()
        ->and(foreignKeys('escalations'))->toMatchArray([
            'ticket_id' => ['references' => 'tickets', 'on_delete' => 'no action'],
            'escalation_status_id' => ['references' => 'escalation_statuses', 'on_delete' => 'no action'],
            'escalation_reason_id' => ['references' => 'escalation_reasons', 'on_delete' => 'no action'],
            'assigned_user_id' => ['references' => 'users', 'on_delete' => 'no action'],
        ]);
});

test('escalation assignments record who took over and who was replaced', function () {
    $columns = collect(Schema::getColumns('escalation_assignments'))->keyBy('name');

    expect($columns['user_id']['nullable'])->toBeFalse()
        ->and($columns['previous_user_id']['nullable'])->toBeTrue()
        ->and(foreignKeys('escalation_assignments'))->toMatchArray([
            'escalation_id' => ['references' => 'escalations', 'on_delete' => 'no action'],
            'user_id' => ['references' => 'users', 'on_delete' => 'no action'],
            'previous_user_id' => ['references' => 'users', 'on_delete' => 'no action'],
        ])
        ->and(Schema::hasIndex('escalation_assignments', ['escalation_id', 'created_at']))->toBeTrue();
});

test('foreign keys block deletes except the agent tool call token reference', function () {
    $nonRestrictingForeignKeys = collect(DB::select(
        "select conrelid::regclass::text as table_name, conname from pg_constraint where contype = 'f' and confdeltype <> 'a'"
    ))->map(fn (object $constraint): string => $constraint->table_name.'.'.$constraint->conname)->all();

    expect($nonRestrictingForeignKeys)->toBe(['agent_tool_calls.agent_tool_calls_personal_access_token_id_foreign']);
});
