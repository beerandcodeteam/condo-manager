<?php

use App\Models\Condominium;
use App\Models\Resident;
use App\Models\User;
use Database\Seeders\LookupSeeder;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;

beforeEach(function () {
    $this->seed(LookupSeeder::class);

    Route::middleware(['api', 'agent'])->prefix('api/v1')->name('api.v1.')->group(function () {
        Route::get('/test/residents', fn () => ['resident_ids' => Resident::query()->orderBy('id')->pluck('id')])
            ->name('residents_lookup');
    });

    $this->condominium = Condominium::factory()->create();
    $this->otherCondominium = Condominium::factory()->create();
});

test('request without token responds 401 unauthenticated', function () {
    $this->getJson('/api/v1/test/residents')
        ->assertUnauthorized()
        ->assertExactJson(['code' => 'unauthenticated', 'message' => 'Token de acesso ausente ou inválido.']);
});

test('revoked token responds 401', function () {
    $newToken = $this->condominium->createToken('n8n');
    $newToken->accessToken->delete();

    $this->withToken($newToken->plainTextToken)
        ->getJson('/api/v1/test/residents')
        ->assertUnauthorized()
        ->assertJsonPath('code', 'unauthenticated');
});

test('token of a condominium only sees its own residents regardless of request parameters', function () {
    $ownResident = Resident::factory()->for($this->condominium)->create();
    Resident::factory()->for($this->otherCondominium)->create();

    $this->withToken($this->condominium->createToken('n8n')->plainTextToken)
        ->getJson('/api/v1/test/residents?condominium_id='.$this->otherCondominium->id)
        ->assertOk()
        ->assertExactJson(['resident_ids' => [$ownResident->id]]);
});

test('using a token fills its last used at', function () {
    $newToken = $this->condominium->createToken('n8n');

    expect($newToken->accessToken->last_used_at)->toBeNull();

    $this->withToken($newToken->plainTextToken)->getJson('/api/v1/test/residents')->assertOk();

    expect($newToken->accessToken->fresh()->last_used_at)->not->toBeNull();
});

test('token issued to a user responds 401', function () {
    $user = User::factory()->sindico()->for($this->condominium)->create();
    $plainTextToken = Str::random(40);

    $accessToken = PersonalAccessToken::query()->forceCreate([
        'tokenable_type' => $user->getMorphClass(),
        'tokenable_id' => $user->id,
        'name' => 'user token',
        'token' => hash('sha256', $plainTextToken),
        'abilities' => ['*'],
    ]);

    $this->withToken("{$accessToken->id}|{$plainTextToken}")
        ->getJson('/api/v1/test/residents')
        ->assertUnauthorized()
        ->assertJsonPath('code', 'unauthenticated');
});
