<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Media a resident sent over WhatsApp. The n8n flow uploads it as soon as it arrives, and the agent
     * may later attach it to a ticket by id — so a photo sent before the problem is described is not lost.
     */
    public function up(): void
    {
        Schema::create('agent_media_kinds', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->timestamps();
        });

        Schema::create('agent_media', function (Blueprint $table) {
            $table->id();
            $table->foreignId('condominium_id')->constrained('condominiums');
            $table->foreignId('agent_media_kind_id')->constrained('agent_media_kinds');
            $table->foreignId('resident_id')->nullable()->constrained('residents');
            // The ticket that consumed this media, if any. Null means still available to attach.
            $table->foreignId('ticket_id')->nullable()->constrained('tickets');
            $table->string('phone', 20);
            $table->string('file_path');
            $table->string('mime_type');
            $table->integer('size_bytes');
            $table->string('caption')->nullable();
            $table->text('transcription')->nullable();
            $table->timestamps();

            $table->index(['condominium_id', 'phone', 'ticket_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('agent_media');
        Schema::dropIfExists('agent_media_kinds');
    }
};
