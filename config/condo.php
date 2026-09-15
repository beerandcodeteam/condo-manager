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
