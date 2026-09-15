<?php

use App\Exceptions\Api\AdvanceNoticeViolation;
use App\Exceptions\Api\ApiException;
use App\Exceptions\Api\ResidentNotFound;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

beforeEach(function () {
    Route::middleware('api')->prefix('api/v1')->group(function () {
        Route::post('/test/validation', fn (Request $request) => $request->validate(['phone' => ['required', 'string']]));
        Route::get('/test/api-exception', fn () => throw new ApiException(422, 'test_error', 'Erro de teste.', ['limit' => 3]));
        Route::get('/test/advance-notice', fn () => throw new AdvanceNoticeViolation(24, 30));
        Route::get('/test/resident-not-found', fn () => throw new ResidentNotFound);
        Route::get('/test/server-error', fn () => throw new RuntimeException('Falha secreta'));
    });
});

test('validation error responds 422 with code, pt-BR message and errors', function () {
    $this->postJson('/api/v1/test/validation')
        ->assertUnprocessable()
        ->assertExactJsonStructure(['code', 'message', 'errors' => ['phone']])
        ->assertJsonPath('code', 'validation_error')
        ->assertJsonPath('message', 'Os dados enviados são inválidos.');
});

test('api exception renders its code, message and extras', function () {
    $this->getJson('/api/v1/test/api-exception')
        ->assertUnprocessable()
        ->assertExactJson(['code' => 'test_error', 'message' => 'Erro de teste.', 'limit' => 3]);
});

test('domain exceptions render their status, code and extras', function () {
    $this->getJson('/api/v1/test/advance-notice')
        ->assertUnprocessable()
        ->assertJsonPath('code', 'advance_notice_violation')
        ->assertJsonPath('min_advance_hours', 24)
        ->assertJsonPath('max_advance_days', 30);

    $this->getJson('/api/v1/test/resident-not-found')
        ->assertForbidden()
        ->assertJsonPath('code', 'resident_not_found');
});

test('unknown route under api v1 responds 404 json', function () {
    $this->get('/api/v1/does-not-exist')
        ->assertNotFound()
        ->assertExactJson(['code' => 'not_found', 'message' => 'Recurso não encontrado.']);
});

test('unexpected error responds 500 server error without stack trace outside local', function () {
    $response = $this->getJson('/api/v1/test/server-error')
        ->assertInternalServerError()
        ->assertExactJson(['code' => 'server_error', 'message' => 'Erro interno do servidor.']);

    expect($response->getContent())->not->toContain('Falha secreta');
});
