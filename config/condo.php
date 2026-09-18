<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Condominium Timezone
    |--------------------------------------------------------------------------
    |
    | Timestamps are stored in UTC (app.timezone). This timezone is used to
    | resolve "today" and to display dates and times to panel users.
    |
    */

    'timezone' => 'America/Sao_Paulo',

    /*
    |--------------------------------------------------------------------------
    | Rules Search (RAG)
    |--------------------------------------------------------------------------
    */

    'rag' => [
        'min_similarity' => (float) env('RAG_MIN_SIMILARITY', 0.5),
        'default_limit' => 5,
        'max_limit' => 10,
        'max_query_length' => (int) env('RAG_MAX_QUERY_LENGTH', 2000),
        'embedding_dimensions' => 1536,
    ],

    /*
    |--------------------------------------------------------------------------
    | Maintenance Tickets
    |--------------------------------------------------------------------------
    */

    'tickets' => [
        'max_photos' => 5,
        'max_photo_kb' => 10240,
        'photo_mimes' => ['jpg', 'jpeg', 'png', 'webp', 'heic'],
        'notice_max_length' => 1000,
    ],

    /*
    |--------------------------------------------------------------------------
    | Agent Conversations
    |--------------------------------------------------------------------------
    |
    | Conversation history the n8n flow reads before each turn and appends to
    | after. The memory of the WhatsApp agent lives here, not inside n8n.
    |
    */

    'conversations' => [
        'default_limit' => 20,
        'max_limit' => 100,
        'max_content_length' => 4000,
        'max_messages_per_request' => 10,

        // Janela em que fragmentos do mesmo morador são juntados numa mensagem só. Custa essa
        // latência em TODA resposta, inclusive de quem mandou uma mensagem única.
        'buffer_seconds' => (int) env('CONDO_BUFFER_SECONDS', 20),
        'buffer_ttl_seconds' => 300,
        'buffer_max_fragments' => 20,

        // Quanto tempo lembramos de um id enviado, para não reprocessar o próprio eco.
        'echo_ttl_seconds' => 3600,
    ],

    /*
    |--------------------------------------------------------------------------
    | WhatsApp Media
    |--------------------------------------------------------------------------
    |
    | Media the resident sends over WhatsApp, uploaded by the n8n flow. Only
    | `imagem` can become a ticket photo; the rest is kept for the record.
    |
    */

    'media' => [
        'disk' => 'local',
        'max_kb' => [
            'imagem' => 10240,
            'audio' => 20480,
            'video' => 30720,
            'documento' => 20480,
        ],
        // How far back the agent may still reach for media that was never attached.
        'pending_hours' => 24,
        'pending_limit' => 5,
    ],

    /*
    |--------------------------------------------------------------------------
    | Platform Credential (n8n)
    |--------------------------------------------------------------------------
    |
    | Shared secret the n8n flow uses on /api/v1/auth/resolve, the only endpoint
    | reachable before a condominium is known. Leaving it empty disables the
    | endpoint: every request is rejected with 401.
    |
    */

    'platform' => [
        'token' => env('CONDO_PLATFORM_TOKEN'),
        'agent_token_ttl_minutes' => (int) env('CONDO_AGENT_TOKEN_TTL_MINUTES', 15),
    ],

    /*
    |--------------------------------------------------------------------------
    | Outgoing Webhooks
    |--------------------------------------------------------------------------
    */

    'webhooks' => [
        'tries' => 3,
        'backoff' => [10, 60],
        'timeout' => 10,
    ],

    /*
    |--------------------------------------------------------------------------
    | Dashboard
    |--------------------------------------------------------------------------
    */

    'dashboard' => [
        'activity_limit' => 6,
        'waiting_limit' => 3,
    ],

    /*
    |--------------------------------------------------------------------------
    | Reservations
    |--------------------------------------------------------------------------
    */

    'reservations' => [
        'whatsapp_card_limit' => 10,
    ],

];
