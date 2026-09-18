<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Conversation history of the WhatsApp agent. The n8n flow reads it before each turn and writes
     * both sides after, so the memory lives with the condominium instead of inside n8n.
     */
    public function up(): void
    {
        Schema::create('agent_message_roles', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->timestamps();
        });

        Schema::create('agent_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('condominium_id')->constrained('condominiums');
            $table->foreignId('agent_message_role_id')->constrained('agent_message_roles');
            $table->foreignId('resident_id')->nullable()->constrained('residents');
            $table->string('phone', 20);
            $table->text('content');
            $table->timestamps();

            // Leitura do histórico de uma conversa: sempre por condomínio + telefone, em ordem de id.
            $table->index(['condominium_id', 'phone', 'id']);
            $table->index(['resident_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('agent_messages');
        Schema::dropIfExists('agent_message_roles');
    }
};
