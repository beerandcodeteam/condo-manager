<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Global lookup tables referenced by domain tables instead of enum columns.
     *
     * @var list<string>
     */
    private array $lookupTables = [
        'roles',
        'resident_profiles',
        'ticket_priorities',
        'reservation_origins',
        'escalation_reasons',
        'tool_call_results',
        'agent_tools',
        'document_types',
        'document_statuses',
        'ticket_statuses',
        'ticket_origins',
        'reservation_statuses',
        'reservation_cancellation_origins',
        'escalation_statuses',
        'webhook_events',
        'webhook_delivery_statuses',
    ];

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        foreach ($this->lookupTables as $lookupTable) {
            Schema::create($lookupTable, function (Blueprint $table) use ($lookupTable) {
                $table->id();
                $table->string('name');
                $table->string('slug')->unique();

                if ($lookupTable === 'ticket_statuses') {
                    $table->boolean('is_final')->default(false);
                }

                if ($lookupTable === 'agent_tools') {
                    $table->string('http_method', 10);
                    $table->string('route');
                    $table->string('description');
                }

                $table->timestamps();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        foreach (array_reverse($this->lookupTables) as $lookupTable) {
            Schema::dropIfExists($lookupTable);
        }
    }
};
