<?php

use App\Models\Condominium;
use App\Models\Notice;
use App\Models\Resident;
use App\Models\Ticket;
use App\Models\Unit;
use App\Models\User;
use App\Support\Tenancy\CondominiumScope;
use App\Support\Tenancy\CurrentCondominium;
use Database\Seeders\LookupSeeder;
use Illuminate\Support\Facades\Route;

beforeEach(function () {
    $this->seed(LookupSeeder::class);
});

test('queries only return rows of the current condominium', function (string $model) {
    [$condominiumA, $condominiumB] = Condominium::factory()->count(2)->create();
    $recordOfA = $model::factory()->for($condominiumA)->create();
    $model::factory()->for($condominiumB)->create();

    app(CurrentCondominium::class)->set($condominiumA);

    expect($model::pluck('id')->all())->toBe([$recordOfA->id]);
})->with([
    'residents' => Resident::class,
    'tickets' => Ticket::class,
    'notices' => Notice::class,
]);

test('creating a model without condominium_id fills the current condominium', function () {
    $condominium = Condominium::factory()->create();
    app(CurrentCondominium::class)->set($condominium);

    $unit = Unit::create(['number' => '102']);

    expect($unit->condominium_id)->toBe($condominium->id)
        ->and($unit->condominium->is($condominium))->toBeTrue();
});

test('creating a model keeps an explicit condominium_id', function () {
    [$condominiumA, $condominiumB] = Condominium::factory()->count(2)->create();
    app(CurrentCondominium::class)->set($condominiumA);

    $unit = Unit::factory()->for($condominiumB)->create();

    expect($unit->condominium_id)->toBe($condominiumB->id);
});

test('without a current condominium all rows are visible', function () {
    Ticket::factory()->count(2)->create();
    Notice::factory()->count(2)->create();

    expect(Resident::count())->toBe(2)
        ->and(Ticket::count())->toBe(2)
        ->and(Notice::count())->toBe(2);
});

test('clearing the current condominium removes the filter', function () {
    $residents = Resident::factory()->count(2)->create();
    $currentCondominium = app(CurrentCondominium::class);
    $currentCondominium->set($residents->first()->condominium);

    $currentCondominium->clear();

    expect($currentCondominium->id())->toBeNull()
        ->and(Resident::count())->toBe(2);
});

test('the scope can be bypassed with withoutGlobalScope', function () {
    $residents = Resident::factory()->count(2)->create();
    app(CurrentCondominium::class)->set($residents->first()->condominium);

    expect(Resident::withoutGlobalScope(CondominiumScope::class)->count())->toBe(2);
});

test('route model binding of another condominium record returns 404', function () {
    Route::middleware('web')->get('/_tenancy-test/residents/{resident}', fn (Resident $resident) => $resident->name);
    [$residentOfA, $residentOfB] = Resident::factory()->count(2)->create();
    app(CurrentCondominium::class)->set($residentOfA->condominium);

    $this->get("/_tenancy-test/residents/{$residentOfB->id}")->assertNotFound();
    $this->get("/_tenancy-test/residents/{$residentOfA->id}")->assertOk();
});

test('users are not scoped by the current condominium', function () {
    [$userOfA] = User::factory()->count(2)->create();
    app(CurrentCondominium::class)->set($userOfA->condominium);

    expect(User::count())->toBe(2);
});
