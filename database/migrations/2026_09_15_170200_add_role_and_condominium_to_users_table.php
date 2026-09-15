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
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('role_id')->after('remember_token')->constrained('roles');
            $table->foreignId('condominium_id')->nullable()->after('role_id')->constrained('condominiums');
            $table->boolean('is_active')->default(true)->after('condominium_id');

            $table->index(['condominium_id', 'role_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['condominium_id', 'role_id']);
            $table->dropConstrainedForeignId('condominium_id');
            $table->dropConstrainedForeignId('role_id');
            $table->dropColumn('is_active');
        });
    }
};
