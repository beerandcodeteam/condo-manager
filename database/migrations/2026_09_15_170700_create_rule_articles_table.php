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
        Schema::create('rule_articles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('condominium_id')->constrained('condominiums');
            $table->foreignId('rule_document_id')->constrained('rule_documents');
            $table->string('reference');
            $table->string('title')->nullable();
            $table->text('body');
            $table->integer('position');
            $table->vector('embedding', dimensions: 1536)->nullable()->index();
            $table->timestamp('embedded_at')->nullable();
            $table->timestamps();

            $table->index(['rule_document_id', 'position']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('rule_articles');
    }
};
