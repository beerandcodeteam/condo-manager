<?php

namespace App\Services\Integration;

use App\Exceptions\Api\ResidentNotFound;
use App\Models\Resident;
use App\Support\PhoneNumber;
use App\Support\Tenancy\CurrentCondominium;
use App\Support\ToolCallContext;

/**
 * Identifies the resident behind a WhatsApp phone number in the condominium of the API token.
 */
class ResidentResolver
{
    public function __construct(
        private CurrentCondominium $currentCondominium,
        private ToolCallContext $toolCallContext,
    ) {}

    /**
     * Resolve the active resident of the current condominium with the given phone, recording it for the tool call log.
     *
     * @throws ResidentNotFound
     */
    public function resolve(string $phone): Resident
    {
        $normalizedPhone = PhoneNumber::normalize($phone);

        $resident = $normalizedPhone === null ? null : Resident::query()
            ->where('condominium_id', $this->currentCondominium->getOrFail()->id)
            ->where('phone', $normalizedPhone)
            ->active()
            ->with('unit.block')
            ->first();

        $this->toolCallContext->setResident($resident, $normalizedPhone);

        return $resident ?? throw new ResidentNotFound;
    }
}
