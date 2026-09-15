<?php

use App\Models\Resident;
use App\Models\Unit;
use Database\Seeders\LookupSeeder;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->seed(LookupSeeder::class);
});

test('formatted phone is stored in E.164', function () {
    $resident = Resident::factory()->create(['phone' => '+55 (41) 99201-1100']);

    expect(DB::table('residents')->where('id', $resident->id)->value('phone'))->toBe('+5541992011100');
});

test('unit label joins number and block name', function () {
    $unit = Unit::factory()->withBlock()->create(['number' => '102']);
    $unit->block->update(['name' => 'A']);

    expect($unit->fresh()->label)->toBe('102A');
});

test('unit label is just the number without a block', function () {
    expect(Unit::factory()->create(['number' => '102'])->label)->toBe('102');
});

test('active scope excludes inactive residents', function () {
    $activeResident = Resident::factory()->create();
    Resident::factory()->inactive()->create();

    expect(Resident::active()->pluck('id')->all())->toBe([$activeResident->id]);
});

test('first_name returns the first word of the name', function () {
    expect(Resident::factory()->make(['name' => 'Ana Beatriz Souza'])->first_name)->toBe('Ana');
});
