<?php

namespace App\Models;

use App\Support\Tenancy\BelongsToCondominium;
use Database\Factories\AgentToolCallFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * @property int $id
 * @property int $condominium_id
 * @property int $agent_tool_id
 * @property int|null $personal_access_token_id
 * @property int|null $resident_id
 * @property string|null $phone
 * @property int $tool_call_result_id
 * @property int $http_status
 * @property string|null $error_code
 * @property array{article_ids?: list<int>, ticket_id?: int, reservation_id?: int, escalation_id?: int}|null $entities
 * @property int $latency_ms
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['agent_tool_id', 'personal_access_token_id', 'resident_id', 'phone', 'tool_call_result_id', 'http_status', 'error_code', 'entities', 'latency_ms'])]
class AgentToolCall extends Model
{
    /** @use HasFactory<AgentToolCallFactory> */
    use BelongsToCondominium, HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'entities' => 'array',
            'http_status' => 'integer',
            'latency_ms' => 'integer',
        ];
    }

    /**
     * Tool calls that cited the given rule article.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeForArticle(Builder $query, int $id): Builder
    {
        return $query->whereJsonContains($this->qualifyColumn('entities->article_ids'), $id);
    }

    /**
     * @return BelongsTo<AgentTool, $this>
     */
    public function agentTool(): BelongsTo
    {
        return $this->belongsTo(AgentTool::class);
    }

    /**
     * @return BelongsTo<ToolCallResult, $this>
     */
    public function result(): BelongsTo
    {
        return $this->belongsTo(ToolCallResult::class, 'tool_call_result_id');
    }

    /**
     * @return BelongsTo<Resident, $this>
     */
    public function resident(): BelongsTo
    {
        return $this->belongsTo(Resident::class);
    }

    /**
     * @return BelongsTo<PersonalAccessToken, $this>
     */
    public function personalAccessToken(): BelongsTo
    {
        return $this->belongsTo(PersonalAccessToken::class);
    }
}
