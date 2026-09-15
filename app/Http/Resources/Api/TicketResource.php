<?php

namespace App\Http\Resources\Api;

use App\Models\Ticket;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Ticket of the resident's unit as listed to the agent.
 *
 * @mixin Ticket
 */
class TicketResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array{protocol: int, description: string, category: string|null, priority: string, status: string, created_at: string|null, updated_at: string|null}
     */
    public function toArray(Request $request): array
    {
        return [
            'protocol' => $this->protocol_number,
            'description' => $this->description,
            'category' => $this->category?->slug,
            'priority' => $this->priority->slug,
            'status' => $this->status->slug,
            'created_at' => $this->created_at?->copy()->setTimezone(config('condo.timezone'))->toIso8601String(),
            'updated_at' => $this->updated_at?->copy()->setTimezone(config('condo.timezone'))->toIso8601String(),
        ];
    }
}
