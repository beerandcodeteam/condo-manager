<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

test('sanctum personal access tokens table is migrated', function () {
    expect(Schema::hasTable('personal_access_tokens'))->toBeTrue()
        ->and(Schema::hasColumns('personal_access_tokens', [
            'tokenable_type', 'tokenable_id', 'name', 'token', 'abilities', 'last_used_at', 'expires_at',
        ]))->toBeTrue();
});

test('default api user route is removed', function () {
    expect(Route::has('api.user'))->toBeFalse();

    $this->getJson('/api/user')->assertNotFound();
});
