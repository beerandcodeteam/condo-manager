<?php

namespace App\Http\Resources\Api;

use App\Models\AgentMedia;
use App\Models\AgentMediaKind;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Media the agent may still attach to a ticket.
 *
 * @mixin AgentMedia
 */
class AgentMediaResource extends JsonResource
{
    /**
     * @var string|null
     */
    public static $wrap = null;

    /**
     * @return array{id: int, kind: string, attachable: bool, caption: string|null, at: string}
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'kind' => $this->kind->slug,
            // Only an image can become a ticket photo; the agent should not offer the rest.
            'attachable' => in_array($this->kind->slug, AgentMediaKind::ATTACHABLE, true),
            'caption' => $this->caption,
            'at' => $this->created_at?->toIso8601String() ?? '',
        ];
    }
}
