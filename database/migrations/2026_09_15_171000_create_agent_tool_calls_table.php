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
        Schema::create('agent_tool_calls', function (Blueprint $table) {
            $table->id();
            $table->foreignId('condominium_id')->constrained('condominiums');
            $table->foreignId('agent_tool_id')->constrained('agent_tools');
            $table->foreignId('personal_access_token_id')->nullable()->constrained('personal_access_tokens')->nullOnDelete();
            $table->foreignId('resident_id')->nullable()->constrained('residents');
            $table->string('phone', 20)->nullable();
            $table->foreignId('tool_call_result_id')->constrained('tool_call_results');
            $table->smallInteger('http_status');
            $table->string('error_code')->nullable();
            $table->jsonb('entities')->nullable();
            $table->integer('latency_ms');
            $table->timestamps();

            $table->index(['condominium_id', 'created_at']);
            $table->index(['condominium_id', 'agent_tool_id', 'created_at']);
            $table->index(['resident_id', 'created_at']);
        });

        DB::statement('create index agent_tool_calls_entities_gin_index on agent_tool_calls using gin (entities jsonb_path_ops)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('agent_tool_calls');
    }
};
