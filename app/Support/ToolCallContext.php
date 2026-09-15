<?php

namespace App\Support;

use App\Models\Resident;

/**
 * What an agent API request touched (resident, phone, entities) and whether it found nothing,
 * collected during the request and written to the tool call log by LogToolCall.
 */
class ToolCallContext
{
    private ?Resident $resident = null;

    private ?string $phone = null;

    /**
     * @var list<int>
     */
    private array $articleIds = [];

    private ?int $ticketId = null;

    private ?int $reservationId = null;

    private ?int $escalationId = null;

    private bool $isEmpty = false;

    public function setResident(?Resident $resident, ?string $phone): void
    {
        $this->resident = $resident;
        $this->phone = $phone ?? $resident?->phone;
    }

    /**
     * @param  array<int, int>  $ids
     */
    public function addArticles(array $ids): void
    {
        $this->articleIds = array_values(array_unique([...$this->articleIds, ...array_map('intval', $ids)]));
    }

    public function setTicket(int $id): void
    {
        $this->ticketId = $id;
    }

    public function setReservation(int $id): void
    {
        $this->reservationId = $id;
    }

    public function setEscalation(int $id): void
    {
        $this->escalationId = $id;
    }

    /**
     * The request succeeded but returned no items (e.g. `results: []`); logged as `vazio`.
     */
    public function markEmpty(): void
    {
        $this->isEmpty = true;
    }

    public function resident(): ?Resident
    {
        return $this->resident;
    }

    public function phone(): ?string
    {
        return $this->phone;
    }

    public function isEmpty(): bool
    {
        return $this->isEmpty;
    }

    /**
     * Entities involved in the request, as stored in `agent_tool_calls.entities`; null when there are none.
     *
     * @return array{article_ids?: list<int>, ticket_id?: int, reservation_id?: int, escalation_id?: int}|null
     */
    public function entities(): ?array
    {
        $entities = array_filter([
            'article_ids' => $this->articleIds,
            'ticket_id' => $this->ticketId,
            'reservation_id' => $this->reservationId,
            'escalation_id' => $this->escalationId,
        ], fn (mixed $value): bool => $value !== null && $value !== []);

        return $entities === [] ? null : $entities;
    }
}
