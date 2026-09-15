<?php

namespace App\Http\Resources\Api;

use App\Models\Ticket;
use App\Models\TicketStatusChange;
use Illuminate\Http\Request;

/**
 * Ticket detail for the agent: the list fields plus the status history in chronological order.
 *
 * @mixin Ticket
 */
class TicketDetailResource extends TicketResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array{protocol: int, description: string, category: string|null, priority: string, status: string, created_at: string|null, updated_at: string|null, history: list<array{status: string, at: string|null, comment: string|null}>}
     */
    public function toArray(Request $request): array
    {
        return [
            ...parent::toArray($request),
            'history' => array_values($this->statusChanges
                ->map(fn (TicketStatusChange $statusChange): array => [
                    'status' => $statusChange->toStatus->slug,
                    'at' => $statusChange->created_at?->copy()->setTimezone(config('condo.timezone'))->toIso8601String(),
                    'comment' => $statusChange->comment,
                ])
                ->all()),
        ];
    }
}
