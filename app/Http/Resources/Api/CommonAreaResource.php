<?php

namespace App\Http\Resources\Api;

use App\Models\CommonArea;
use App\Models\CommonAreaSlot;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Active common area as listed to the agent, with its slots and advance rules.
 *
 * @mixin CommonArea
 */
class CommonAreaResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array{id: int, name: string, description: string|null, slots: list<array{id: int, starts: string, ends: string}>, rules: array{min_advance_hours: int, max_advance_days: int, cancellation_deadline_hours: int}}
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'slots' => array_values($this->slots->map(fn (CommonAreaSlot $slot): array => [
                'id' => $slot->id,
                'starts' => $slot->starts,
                'ends' => $slot->ends,
            ])->all()),
            'rules' => [
                'min_advance_hours' => $this->min_advance_hours,
                'max_advance_days' => $this->max_advance_days,
                'cancellation_deadline_hours' => $this->cancellation_deadline_hours,
            ],
        ];
    }
}
