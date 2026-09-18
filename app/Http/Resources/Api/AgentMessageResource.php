<?php

namespace App\Http\Resources\Api;

use App\Models\AgentMessage;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One turn of the conversation, in the order the agent should replay it.
 *
 * @mixin AgentMessage
 */
class AgentMessageResource extends JsonResource
{
    /**
     * @var string|null
     */
    public static $wrap = null;

    /**
     * @return array{id: int, role: string, content: string, at: string}
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'role' => $this->role->slug,
            'content' => $this->content,
            'at' => $this->created_at?->toIso8601String() ?? '',
        ];
    }
}
