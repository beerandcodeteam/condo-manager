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

    /**
     * The current condominium of a tenant-bound context (panel pages resolved by SetPanelCondominium).
     */
    public function getOrFail(): Condominium
    {
        return $this->condominium ?? abort(404);
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
