<?php

namespace App\Services\Registry;

use App\Exceptions\DeletionBlockedException;
use App\Models\Condominium;
use App\Models\Unit;

class UnitService
{
    /**
     * @param  array{number: string, block_id: int|null}  $attributes
     */
    public function create(Condominium $condominium, array $attributes): Unit
    {
        return $condominium->units()->create([
            'number' => trim($attributes['number']),
            'block_id' => $attributes['block_id'],
        ]);
    }

    /**
     * @param  array{number: string, block_id: int|null}  $attributes
     */
    public function update(Unit $unit, array $attributes): Unit
    {
        $unit->update([
            'number' => trim($attributes['number']),
            'block_id' => $attributes['block_id'],
        ]);

        return $unit;
    }

    /**
     * @throws DeletionBlockedException when residents, tickets, reservations or escalations reference the unit
     */
    public function delete(Unit $unit): void
    {
        $isInUse = $unit->residents()->exists()
            || $unit->tickets()->exists()
            || $unit->reservations()->exists()
            || $unit->escalations()->exists();

        if ($isInUse) {
            throw new DeletionBlockedException('Unidade com moradores, chamados ou reservas não pode ser excluída.');
        }

        $unit->delete();
    }
}
