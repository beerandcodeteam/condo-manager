<?php

use App\Models\Condominium;
use Illuminate\Support\Facades\DB;

test('webhook_secret is encrypted in the database', function () {
    $condominium = Condominium::create(['name' => 'Residencial Aurora', 'webhook_secret' => 'segredo-do-webhook']);

    $rawSecret = DB::table('condominiums')->where('id', $condominium->id)->value('webhook_secret');

    expect($rawSecret)->not->toBe('segredo-do-webhook')
        ->and(decrypt($rawSecret, false))->toBe('segredo-do-webhook')
        ->and($condominium->fresh()->webhook_secret)->toBe('segredo-do-webhook');
});

test('createToken issues a token whose tokenable is the condominium', function () {
    $condominium = Condominium::create(['name' => 'Residencial Aurora']);

    $newToken = $condominium->createToken('n8n');

    expect($newToken->accessToken->tokenable->is($condominium))->toBeTrue()
        ->and($condominium->tokens()->count())->toBe(1);
});

test('hasWebhook is false without a webhook url', function (?string $webhookUrl) {
    $condominium = Condominium::create(['name' => 'Residencial Aurora', 'webhook_url' => $webhookUrl]);

    expect($condominium->hasWebhook())->toBeFalse();
})->with([
    'null' => [null],
    'empty string' => [''],
]);

test('hasWebhook is true with a webhook url', function () {
    $condominium = Condominium::create(['name' => 'Residencial Aurora', 'webhook_url' => 'https://n8n.example.com/webhook/condo']);

    expect($condominium->hasWebhook())->toBeTrue();
});
