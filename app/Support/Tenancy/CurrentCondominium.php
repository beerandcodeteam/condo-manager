<?php

namespace App\Support\Tenancy;

use App\Models\Condominium;

/**
 * Holds the condominium (tenant) of the current request, job or console context.
 */
class CurrentCondominium
{
    private ?Condominium $condominium = null;

    public function set(Condominium $condominium): void
    {
        $this->condominium = $condominium;
    }

    public function get(): ?Condominium
    {
        return $this->condominium;
    }

    public function id(): ?int
    {
        return $this->condominium?->getKey();
    }

    public function clear(): void
    {
        $this->condominium = null;
    }
}
