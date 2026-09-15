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
        Schema::create('residents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('condominium_id')->constrained('condominiums');
            $table->foreignId('unit_id')->index()->constrained('units');
            $table->foreignId('resident_profile_id')->constrained('resident_profiles');
            $table->string('name');
            $table->string('phone', 20);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['condominium_id', 'phone']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('residents');
    }
};
