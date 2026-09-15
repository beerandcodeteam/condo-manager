<?php

namespace App\Services\Tickets;

use App\Exceptions\DeletionBlockedException;
use App\Models\Condominium;
use App\Models\TicketCategory;
use Illuminate\Support\Str;

/**
 * Ticket categories of a condominium. The slug is generated once and never changes on rename.
 */
class TicketCategoryService
{
    public function slugFor(string $name): string
    {
        return Str::slug($name);
    }

    public function create(Condominium $condominium, string $name): TicketCategory
    {
        return $condominium->ticketCategories()->create([
            'name' => trim($name),
            'slug' => $this->slugFor($name),
            'is_active' => true,
        ]);
    }

    public function rename(TicketCategory $category, string $name): TicketCategory
    {
        $category->update(['name' => trim($name)]);

        return $category;
    }

    /**
     * Activate or deactivate the category; always allowed, even when tickets use it.
     */
    public function setActive(TicketCategory $category, bool $isActive): TicketCategory
    {
        $category->update(['is_active' => $isActive]);

        return $category;
    }

    /**
     * @throws DeletionBlockedException when a ticket uses the category
     */
    public function delete(TicketCategory $category): void
    {
        if ($category->tickets()->exists()) {
            throw new DeletionBlockedException('Categoria em uso: inative em vez de excluir.');
        }

        $category->delete();
    }
}
