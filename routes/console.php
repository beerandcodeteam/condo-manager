<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// The agent tokens minted per WhatsApp conversation expire on their own; this clears the dead rows.
Schedule::command('sanctum:prune-expired --hours=24')->daily();
