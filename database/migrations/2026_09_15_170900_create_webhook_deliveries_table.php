<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('webhook_deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('condominium_id')->constrained('condominiums');
            $table->foreignId('webhook_event_id')->constrained('webhook_events');
            $table->foreignId('webhook_delivery_status_id')->constrained('webhook_delivery_statuses');
            $table->morphs('subject');
            $table->string('resident_phone', 20)->nullable();
            $table->string('url');
            $table->jsonb('payload');
            $table->integer('attempts')->default(0);
            $table->integer('last_response_code')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamps();

            $table->index(['condominium_id', 'webhook_delivery_status_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('webhook_deliveries');
    }
};
