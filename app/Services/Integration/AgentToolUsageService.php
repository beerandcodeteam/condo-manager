<?php

namespace App\Services\Integration;

use App\Models\AgentTool;
use App\Models\Condominium;
use App\Support\Tenancy\CondominiumScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * Usage of the agent tools (API endpoints) derived from the tool call log.
 */
class AgentToolUsageService
{
    public const RECENT_DAYS = 7;

    /**
     * Every agent tool with `recent_calls_count` = calls of the condominium in the last 7 days.
     *
     * @return Collection<int, AgentTool>
     */
    public function recentCalls(Condominium $condominium): Collection
    {
        return AgentTool::query()
            ->withCount(['toolCalls as recent_calls_count' => fn (Builder $query) => $query
                ->withoutGlobalScope(CondominiumScope::class)
                ->where('condominium_id', $condominium->id)
                ->where('created_at', '>=', now()->subDays(self::RECENT_DAYS)),
            ])
            ->orderBy('id')
            ->get();
    }
}
