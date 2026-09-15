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
        Schema::create('tickets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('condominium_id')->constrained('condominiums');
            $table->integer('protocol_number');
            $table->foreignId('ticket_status_id')->constrained('ticket_statuses');
            $table->foreignId('ticket_priority_id')->constrained('ticket_priorities');
            $table->foreignId('ticket_origin_id')->constrained('ticket_origins');
            $table->foreignId('ticket_category_id')->nullable()->constrained('ticket_categories');
            $table->foreignId('unit_id')->nullable()->constrained('units');
            $table->foreignId('resident_id')->nullable()->constrained('residents');
            $table->foreignId('opened_by_user_id')->nullable()->constrained('users');
            $table->text('description');
            $table->string('location')->nullable();
            $table->timestamps();

            $table->unique(['condominium_id', 'protocol_number']);
            $table->index(['condominium_id', 'ticket_status_id', 'ticket_priority_id']);
            $table->index(['unit_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tickets');
    }
};
