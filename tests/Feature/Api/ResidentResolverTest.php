<?php

use App\Exceptions\Api\ResidentNotFound;
use App\Models\Condominium;
use App\Models\Resident;
use App\Services\Integration\ResidentResolver;
use App\Support\Tenancy\CurrentCondominium;
use App\Support\ToolCallContext;
use Database\Seeders\LookupSeeder;

beforeEach(function () {
    $this->seed(LookupSeeder::class);

    $this->condominium = Condominium::factory()->create();
    app(CurrentCondominium::class)->set($this->condominium);
});

test('formatted phone resolves the active resident and records it in the tool call context', function () {
    $resident = Resident::factory()->for($this->condominium)->create(['phone' => '+5541998123344']);

    $resolved = app(ResidentResolver::class)->resolve('+55 (41) 99812-3344');

    expect($resolved->is($resident))->toBeTrue()
        ->and(app(ToolCallContext::class)->resident()?->is($resident))->toBeTrue()
        ->and(app(ToolCallContext::class)->phone())->toBe('+5541998123344');
});

test('inactive resident throws resident not found keeping the normalized phone in the context', function () {
    Resident::factory()->inactive()->for($this->condominium)->create(['phone' => '+5541998123344']);

    expect(fn () => app(ResidentResolver::class)->resolve('+55 41 99812-3344'))
        ->toThrow(ResidentNotFound::class);

    expect(app(ToolCallContext::class)->resident())->toBeNull()
        ->and(app(ToolCallContext::class)->phone())->toBe('+5541998123344');
});

test('phone of a resident of another condominium throws resident not found', function () {
    Resident::factory()->for(Condominium::factory())->create(['phone' => '+5541998123344']);

    try {
        app(ResidentResolver::class)->resolve('+5541998123344');
        $this->fail('ResidentNotFound was not thrown.');
    } catch (ResidentNotFound $exception) {
        expect($exception->errorCode)->toBe('resident_not_found')
            ->and($exception->status)->toBe(403);
    }
});
