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
        Schema::create('escalations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('condominium_id')->constrained('condominiums');
            $table->foreignId('resident_id')->constrained('residents');
            $table->foreignId('unit_id')->constrained('units');
            $table->foreignId('ticket_id')->nullable()->constrained('tickets');
            $table->foreignId('escalation_status_id')->constrained('escalation_statuses');
            $table->foreignId('escalation_reason_id')->constrained('escalation_reasons');
            $table->text('summary');
            $table->foreignId('assigned_user_id')->nullable()->constrained('users');
            $table->timestamp('assigned_at')->nullable();
            $table->text('response')->nullable();
            $table->foreignId('responded_by_user_id')->nullable()->constrained('users');
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->index(['condominium_id', 'escalation_status_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('escalations');
    }
};
