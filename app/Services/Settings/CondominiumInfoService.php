<?php

namespace App\Services\Settings;

use App\Models\Condominium;
use App\Models\User;
use App\Support\PhoneNumber;
use App\Support\Tenancy\CondominiumScope;

/**
 * Informative data of the condominium shown on the settings page. No API rule depends on it.
 */
class CondominiumInfoService
{
    /**
     * Update the informative data; only the super admin may rename the condominium.
     *
     * @param  array{name?: string|null, city?: string|null, whatsapp_number?: string|null, caretaker_name?: string|null, caretaker_phone?: string|null, quiet_hours_start?: string|null, quiet_hours_end?: string|null}  $attributes
     */
    public function update(Condominium $condominium, User $user, array $attributes): Condominium
    {
        $data = [
            'city' => $this->nullableText($attributes['city'] ?? null),
            'whatsapp_number' => $this->nullablePhone($attributes['whatsapp_number'] ?? null),
            'caretaker_name' => $this->nullableText($attributes['caretaker_name'] ?? null),
            'caretaker_phone' => $this->nullablePhone($attributes['caretaker_phone'] ?? null),
            'quiet_hours_start' => $this->nullableText($attributes['quiet_hours_start'] ?? null),
            'quiet_hours_end' => $this->nullableText($attributes['quiet_hours_end'] ?? null),
        ];

        if ($user->isSuperAdmin() && filled($attributes['name'] ?? null)) {
            $data['name'] = trim((string) $attributes['name']);
        }

        $condominium->update($data);

        return $condominium;
    }

    /**
     * Units summary such as "128 · 2 blocos".
     */
    public function unitsSummary(Condominium $condominium): string
    {
        $unitCount = $condominium->units()->withoutGlobalScope(CondominiumScope::class)->count();
        $blockCount = $condominium->blocks()->withoutGlobalScope(CondominiumScope::class)->count();

        return $unitCount.' · '.$blockCount.' '.($blockCount === 1 ? 'bloco' : 'blocos');
    }

    private function nullableText(?string $value): ?string
    {
        return filled($value) ? trim($value) : null;
    }

    private function nullablePhone(?string $value): ?string
    {
        return filled($value) ? (PhoneNumber::normalize($value) ?? trim($value)) : null;
    }
}
