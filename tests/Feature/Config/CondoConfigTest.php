<?php

test('app keeps UTC timezone and uses pt_BR locale', function () {
    expect(config('app.timezone'))->toBe('UTC')
        ->and(config('app.locale'))->toBe('pt_BR')
        ->and(config('app.fallback_locale'))->toBe('pt_BR')
        ->and(config('app.faker_locale'))->toBe('pt_BR');
});

test('pt_BR translations are loaded for validation, auth and pagination', function () {
    expect(__('validation.required', ['attribute' => 'nome']))->toBe('O campo nome é obrigatório.')
        ->and(__('auth.failed'))->toBe('Essas credenciais não correspondem aos nossos registros.')
        ->and(__('pagination.next'))->toBe('Próximo &raquo;');
});

test('condo config exposes domain defaults', function () {
    expect(config('condo.timezone'))->toBe('America/Sao_Paulo')
        ->and(config('condo.rag'))->toBe([
            'min_similarity' => 0.5,
            'default_limit' => 5,
            'max_limit' => 10,
            'embedding_dimensions' => 1536,
        ])
        ->and(config('condo.tickets'))->toBe([
            'max_photos' => 5,
            'max_photo_kb' => 10240,
            'photo_mimes' => ['jpg', 'jpeg', 'png', 'webp', 'heic'],
            'notice_max_length' => 1000,
        ])
        ->and(config('condo.webhooks'))->toBe([
            'tries' => 3,
            'backoff' => [10, 60],
            'timeout' => 10,
        ])
        ->and(config('condo.dashboard'))->toBe([
            'activity_limit' => 6,
            'waiting_limit' => 3,
        ])
        ->and(config('condo.reservations.whatsapp_card_limit'))->toBe(10);
});

test('ai sdk generates embeddings with openai text-embedding-3-small at 1536 dimensions', function () {
    expect(config('ai.default_for_embeddings'))->toBe('openai')
        ->and(config('ai.providers.openai.driver'))->toBe('openai')
        ->and(config('ai.providers.openai.models.embeddings'))->toBe([
            'default' => 'text-embedding-3-small',
            'dimensions' => 1536,
        ]);
});
