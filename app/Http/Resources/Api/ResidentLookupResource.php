<?php

namespace App\Http\Resources\Api;

use App\Models\Resident;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Active resident found by the phone lookup, with its unit.
 *
 * @mixin Resident
 */
class ResidentLookupResource extends JsonResource
{
    /**
     * @var string|null
     */
    public static $wrap = null;

    /**
     * Transform the resource into an array.
     *
     * @return array{exists: true, resident: array{id: int, name: string, phone: string}, unit: array{id: int, number: string, block: string|null}}
     */
    public function toArray(Request $request): array
    {
        return [
            'exists' => true,
            'resident' => [
                'id' => $this->id,
                'name' => $this->name,
                'phone' => $this->phone,
            ],
            'unit' => [
                'id' => $this->unit->id,
                'number' => $this->unit->number,
                'block' => $this->unit->block?->name,
            ],
        ];
    }
}
