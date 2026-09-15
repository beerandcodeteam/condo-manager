<?php

namespace App\Http\Resources\Api;

use App\Models\Reservation;
use App\Services\Reservations\ReservationService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Upcoming reservation of the resident's unit as listed to the agent.
 *
 * @mixin Reservation
 */
class ReservationResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array{id: int, area: string, date: string, starts: string, ends: string, resident: string, cancellable: bool}
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'area' => $this->area->name,
            'date' => $this->date->format('Y-m-d'),
            'starts' => $this->starts,
            'ends' => $this->ends,
            'resident' => $this->resident->name,
            'cancellable' => app(ReservationService::class)->isCancellableByResident($this->resource),
        ];
    }
}
