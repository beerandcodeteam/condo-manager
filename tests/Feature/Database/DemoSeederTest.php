<?php

use App\Models\AgentToolCall;
use App\Models\Condominium;
use App\Models\Reservation;
use App\Models\Role;
use App\Models\Ticket;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DemoSeeder;
use Database\Seeders\LookupSeeder;
use Illuminate\Support\Facades\Hash;

test('demo seeder creates Residencial Aurora with 10 residents, 4 areas and 3 escalations', function () {
    $this->seed([LookupSeeder::class, DemoSeeder::class]);

    $aurora = Condominium::where('name', 'Residencial Aurora')->sole();

    expect($aurora->city)->toBe('Curitiba')
        ->and($aurora->residents()->count())->toBe(10)
        ->and($aurora->commonAreas()->count())->toBe(4)
        ->and($aurora->escalations()->count())->toBe(3)
        ->and($aurora->blocks()->pluck('name')->sort()->values()->all())->toBe(['A', 'B']);
});

test('demo seeder creates the three condominiums with the default ticket categories', function () {
    $this->seed([LookupSeeder::class, DemoSeeder::class]);

    $condominiums = Condominium::with('ticketCategories')->get()->keyBy('name');

    expect($condominiums->map->city->all())->toEqual([
        'Residencial Aurora' => 'Curitiba',
        'Edifício Solar das Palmeiras' => 'São Paulo',
        'Villa Serena' => 'Florianópolis',
    ]);
    $condominiums->each(fn (Condominium $condominium) => expect($condominium->ticketCategories->pluck('slug')->all())
        ->toEqualCanonicalizing(['eletrica', 'hidraulica', 'elevador', 'limpeza', 'seguranca', 'outros']));
});

test('demo seeder creates the panel users with password "password"', function () {
    $this->seed([LookupSeeder::class, DemoSeeder::class]);

    $users = User::with('role')->get()->keyBy('email');

    expect($users['admin@example.com']->role->slug)->toBe(Role::SUPER_ADMIN)
        ->and($users['admin@example.com']->condominium_id)->toBeNull()
        ->and($users->firstWhere('name', 'Renata Moura')->role->slug)->toBe(Role::SINDICO)
        ->and($users->firstWhere('name', 'José Carvalho')->role->slug)->toBe(Role::ZELADOR);
    $users->each(fn (User $user) => expect(Hash::check('password', $user->password))->toBeTrue());
});

test('demo seeder creates the design residents, tickets, reservations of the week and recent tool calls', function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-15 14:00:00', 'America/Sao_Paulo'));

    $this->seed([LookupSeeder::class, DemoSeeder::class]);

    $aurora = Condominium::where('name', 'Residencial Aurora')->sole();
    $residentsByUnit = $aurora->residents()->with('unit.block')->get()->mapWithKeys(fn ($resident) => [$resident->unit->label => $resident->name]);
    $startOfWeek = CarbonImmutable::now('America/Sao_Paulo')->startOfWeek(CarbonImmutable::MONDAY)->toDateString();
    $endOfWeek = CarbonImmutable::now('America/Sao_Paulo')->endOfWeek(CarbonImmutable::SUNDAY)->toDateString();
    $todayStartUtc = CarbonImmutable::now('America/Sao_Paulo')->startOfDay()->utc();

    expect($residentsByUnit['101A'])->toBe('Helena Barros')
        ->and($residentsByUnit['1504B'])->toBe('Fernando Lima')
        ->and($aurora->residents()->where('phone', '+5541998123344')->value('name'))->toBe('Carlos Mendes')
        ->and(Ticket::where('protocol_number', 4821)->sole()->resident->name)->toBe('Carlos Mendes')
        ->and(Ticket::open()->count())->toBe(4)
        ->and($aurora->fresh()->last_ticket_protocol)->toBe(4821)
        ->and($aurora->notices()->where('is_active', true)->count())->toBe(3)
        ->and($aurora->notices()->where('is_active', false)->count())->toBe(2)
        ->and(Reservation::whereBetween('date', [$startOfWeek, $endOfWeek])->count())->toBe(7)
        ->and(AgentToolCall::where('created_at', '>=', $todayStartUtc)->count())->toBeGreaterThan(0)
        ->and(AgentToolCall::whereBetween('created_at', [$todayStartUtc->subDay(), $todayStartUtc])->count())->toBeGreaterThan(0)
        ->and(AgentToolCall::where('created_at', '<', $todayStartUtc->subDay())->count())->toBeGreaterThan(0)
        ->and(AgentToolCall::where('created_at', '>', now())->count())->toBe(0);
});

test('database seeder runs the demo seeder only in the local environment', function (string $environment, int $expectedCondominiums) {
    app()->detectEnvironment(fn (): string => $environment);

    $this->seed(DatabaseSeeder::class);

    expect(Condominium::count())->toBe($expectedCondominiums);
})->with([
    'local' => ['local', 3],
    'testing' => ['testing', 0],
    'staging' => ['staging', 0],
]);

test('running the demo seeder twice does not duplicate data', function () {
    $this->seed([LookupSeeder::class, DemoSeeder::class, DemoSeeder::class]);

    expect(Condominium::count())->toBe(3);
});
