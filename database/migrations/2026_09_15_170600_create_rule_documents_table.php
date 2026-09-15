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
        Schema::ensureVectorExtensionExists();

        Schema::create('rule_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('condominium_id')->constrained('condominiums');
            $table->foreignId('document_type_id')->constrained('document_types');
            $table->foreignId('document_status_id')->constrained('document_statuses');
            $table->string('title');
            $table->string('file_path');
            $table->text('processing_error')->nullable();
            $table->foreignId('uploaded_by_user_id')->constrained('users');
            $table->foreignId('published_by_user_id')->nullable()->constrained('users');
            $table->timestamp('published_at')->nullable();
            $table->timestamps();

            $table->index(['condominium_id', 'document_type_id', 'document_status_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('rule_documents');
    }
};
