<?php

namespace App\Http\Resources\Api;

use App\Models\Notice;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Active notice as listed to the agent.
 *
 * @mixin Notice
 */
class NoticeResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array{id: int, title: string, text: string, updated_at: string|null}
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'text' => $this->body,
            'updated_at' => $this->updated_at?->copy()->setTimezone(config('condo.timezone'))->toIso8601String(),
        ];
    }
}
