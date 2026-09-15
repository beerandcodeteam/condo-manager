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
        Schema::create('units', function (Blueprint $table) {
            $table->id();
            $table->foreignId('condominium_id')->constrained('condominiums');
            $table->foreignId('block_id')->nullable()->constrained('blocks');
            $table->string('number');
            $table->timestamps();
        });

        /*
         * Postgres does not treat NULL values as equal in unique indexes, so units
         * with and without a block need separate partial unique indexes.
         */
        DB::statement('create unique index units_block_id_number_unique on units (block_id, number) where block_id is not null');
        DB::statement('create unique index units_condominium_id_number_unique on units (condominium_id, number) where block_id is null');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('units');
    }
};
