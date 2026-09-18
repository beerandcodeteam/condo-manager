<?php

namespace App\Services\Integration;

use App\Models\AgentMessage;
use App\Models\AgentMessageRole;
use App\Models\Resident;
use App\Support\PhoneNumber;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Conversation history of the WhatsApp agent, scoped to the condominium of the API token.
 */
class ConversationService
{
    /**
     * The last messages of this phone's conversation, oldest first so the agent can replay them.
     *
     * @return Collection<int, AgentMessage>
     */
    public function history(string $phone, int $limit): Collection
    {
        /** @var Collection<int, AgentMessage> $messages */
        $messages = AgentMessage::query()
            ->forPhone($phone)
            ->with('role')
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->sortBy('id')
            ->values();

        return $messages;
    }

    /**
     * Append one turn. Both sides are written in a single transaction so a conversation never
     * keeps the question without the answer.
     *
     * @param  list<array{role: string, content: string}>  $messages
     * @return Collection<int, AgentMessage>
     */
    public function append(string $phone, array $messages): Collection
    {
        $normalizedPhone = PhoneNumber::normalize($phone) ?? $phone;

        // The resident is resolved once per append: the same phone cannot be two residents of the
        // same condominium, and a non-resident keeps the conversation with resident_id null.
        $residentId = Resident::query()
            ->where('phone', $normalizedPhone)
            ->active()
            ->value('id');

        return DB::transaction(fn (): Collection => new Collection(array_map(
            fn (array $message): AgentMessage => AgentMessage::create([
                'agent_message_role_id' => AgentMessageRole::idFor($message['role']),
                'resident_id' => $residentId,
                'phone' => $normalizedPhone,
                'content' => $message['content'],
            ]),
            $messages,
        )));
    }
}
