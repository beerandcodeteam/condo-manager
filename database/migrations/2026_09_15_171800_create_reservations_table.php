<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('reservations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('condominium_id')->constrained('condominiums');
            $table->foreignId('common_area_id')->constrained('common_areas');
            $table->foreignId('common_area_slot_id')->constrained('common_area_slots');
            $table->foreignId('unit_id')->constrained('units');
            $table->foreignId('resident_id')->constrained('residents');
            $table->foreignId('reservation_status_id')->constrained('reservation_statuses');
            $table->foreignId('reservation_origin_id')->constrained('reservation_origins');
            $table->foreignId('created_by_user_id')->nullable()->constrained('users');
            $table->date('date');
            $table->time('starts_at');
            $table->time('ends_at');
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('reservation_cancellation_origin_id')->nullable()->constrained('reservation_cancellation_origins');
            $table->text('cancellation_reason')->nullable();
            $table->foreignId('cancelled_by_user_id')->nullable()->constrained('users');
            $table->timestamps();

            $table->index(['unit_id', 'date']);
            $table->index(['common_area_id', 'date']);
        });

        /*
         * Only one active (not cancelled) reservation per slot and date, even under concurrency.
         */
        DB::statement('create unique index reservations_common_area_slot_id_date_unique on reservations (common_area_slot_id, date) where cancelled_at is null');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('reservations');
    }
};
