<?php

namespace App\Services\Registry;

use App\Exceptions\DeletionBlockedException;
use App\Models\Block;
use App\Models\Condominium;

class BlockService
{
    public function create(Condominium $condominium, string $name): Block
    {
        return $condominium->blocks()->create(['name' => trim($name)]);
    }

    public function rename(Block $block, string $name): Block
    {
        $block->update(['name' => trim($name)]);

        return $block;
    }

    /**
     * @throws DeletionBlockedException when the block still has units
     */
    public function delete(Block $block): void
    {
        if ($block->units()->exists()) {
            throw new DeletionBlockedException('Remova as unidades do bloco antes de excluí-lo.');
        }

        $block->delete();
    }
}
