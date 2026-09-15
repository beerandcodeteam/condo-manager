<?php

use Database\Seeders\DatabaseSeeder;
use Database\Seeders\LookupSeeder;
use Illuminate\Support\Facades\DB;

dataset('lookup slugs', [
    'roles' => ['roles', ['super_admin', 'sindico', 'zelador']],
    'resident_profiles' => ['resident_profiles', ['proprietario', 'inquilino']],
    'ticket_priorities' => ['ticket_priorities', ['alta', 'media', 'baixa']],
    'reservation_origins' => ['reservation_origins', ['whatsapp', 'painel']],
    'escalation_reasons' => ['escalation_reasons', ['pediu_humano', 'tool_recusou', 'sem_regra']],
    'tool_call_results' => ['tool_call_results', ['sucesso', 'vazio', 'recusa']],
    'agent_tools' => ['agent_tools', [
        'residents_lookup', 'rules_search', 'notices_list', 'tickets_create', 'tickets_list', 'tickets_show',
        'areas_list', 'areas_availability', 'reservations_create', 'reservations_list', 'reservations_cancel', 'escalations_create',
    ]],
    'document_types' => ['document_types', ['regimento', 'convencao']],
    'document_statuses' => ['document_statuses', ['processando', 'em_revisao', 'falha_extracao', 'indexando', 'falha_indexacao', 'publicado', 'substituido']],
    'ticket_statuses' => ['ticket_statuses', ['aberto', 'em_andamento', 'resolvido', 'cancelado']],
    'ticket_origins' => ['ticket_origins', ['whatsapp', 'painel']],
    'reservation_statuses' => ['reservation_statuses', ['confirmada', 'cancelada']],
    'reservation_cancellation_origins' => ['reservation_cancellation_origins', ['morador', 'sindico']],
    'escalation_statuses' => ['escalation_statuses', ['pendente', 'em_atendimento', 'resolvido']],
    'webhook_events' => ['webhook_events', ['ticket.status_changed', 'ticket.resident_notified', 'reservation.cancelled', 'escalation.answered']],
    'webhook_delivery_statuses' => ['webhook_delivery_statuses', ['pendente', 'enviado', 'falhou']],
]);

test('each lookup has exactly the schema slugs', function (string $table, array $slugs) {
    $this->seed(LookupSeeder::class);

    expect(DB::table($table)->pluck('slug')->all())->toEqualCanonicalizing($slugs);
})->with('lookup slugs');

test('running the seeder twice does not duplicate rows', function (string $table, array $slugs) {
    $this->seed(LookupSeeder::class);
    $firstRunIds = DB::table($table)->orderBy('id')->pluck('id', 'slug')->all();

    $this->seed(LookupSeeder::class);

    expect(DB::table($table)->count())->toBe(count($slugs))
        ->and(DB::table($table)->orderBy('id')->pluck('id', 'slug')->all())->toBe($firstRunIds);
})->with('lookup slugs');

test('ticket statuses have the correct is_final flag', function () {
    $this->seed(LookupSeeder::class);

    expect(DB::table('ticket_statuses')->pluck('is_final', 'slug')->all())->toEqual([
        'aberto' => false,
        'em_andamento' => false,
        'resolvido' => true,
        'cancelado' => true,
    ]);
});

test('agent tools map slugs to displayed names with method, route and description', function () {
    $this->seed(LookupSeeder::class);

    $tools = DB::table('agent_tools')->get()->keyBy('slug');

    expect($tools->map(fn (object $tool): string => "{$tool->name} {$tool->http_method} {$tool->route}")->all())->toEqual([
        'residents_lookup' => 'verificar_morador GET /api/v1/residents/lookup',
        'rules_search' => 'consultar_regimento POST /api/v1/rules/search',
        'notices_list' => 'consultar_comunicados GET /api/v1/notices',
        'tickets_create' => 'abrir_chamado POST /api/v1/tickets',
        'tickets_list' => 'listar_chamados GET /api/v1/tickets',
        'tickets_show' => 'consultar_chamado GET /api/v1/tickets/{protocol}',
        'areas_list' => 'listar_areas GET /api/v1/areas',
        'areas_availability' => 'consultar_disponibilidade GET /api/v1/areas/{id}/availability',
        'reservations_create' => 'reservar_area POST /api/v1/reservations',
        'reservations_list' => 'listar_reservas GET /api/v1/reservations',
        'reservations_cancel' => 'cancelar_reserva DELETE /api/v1/reservations/{id}',
        'escalations_create' => 'escalar_humano POST /api/v1/escalations',
    ]);

    $tools->each(fn (object $tool) => expect($tool->description)->not->toBeEmpty());
});

test('lookup names are seeded in pt-BR', function () {
    $this->seed(LookupSeeder::class);

    expect(DB::table('roles')->where('slug', 'sindico')->value('name'))->toBe('Síndico')
        ->and(DB::table('ticket_priorities')->where('slug', 'media')->value('name'))->toBe('Média')
        ->and(DB::table('document_types')->where('slug', 'regimento')->value('name'))->toBe('Regimento interno');
});

test('database seeder always seeds lookups', function () {
    $this->seed(DatabaseSeeder::class);

    expect(DB::table('roles')->count())->toBe(3)
        ->and(DB::table('agent_tools')->count())->toBe(12);
});
