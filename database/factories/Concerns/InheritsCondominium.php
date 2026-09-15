<?php

namespace Database\Factories\Concerns;

use App\Models\Condominium;
use Closure;
use Illuminate\Database\Eloquent\Factories\BelongsToRelationship;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use ReflectionFunction;

/**
 * Keeps tenant-owned records consistent: a child uses the condominium of a parent that was
 * given explicitly (via for(), state or create attributes) and only creates a new condominium otherwise.
 */
trait InheritsCondominium
{
    /**
     * @param  array<string, mixed>  $attributes
     * @param  array<string, class-string<Model>>  $parents  foreign key => parent model class
     * @return int|Factory<Condominium>
     */
    protected function condominiumFromParents(array $attributes, array $parents): int|Factory
    {
        foreach ($parents as $foreignKey => $parentModel) {
            $condominiumId = $this->parentAttribute($attributes[$foreignKey] ?? null, $parentModel, 'condominium_id');

            if ($condominiumId !== null) {
                return (int) $condominiumId;
            }
        }

        return Condominium::factory();
    }

    /**
     * Read an attribute of a parent given as a model or key; null while the parent is still to be created.
     *
     * @param  class-string<Model>  $parentModel
     */
    protected function parentAttribute(mixed $parent, string $parentModel, string $attribute): mixed
    {
        if ($this->isForRelationshipResolver($parent)) {
            $parent = $parent();
        }

        if ($parent instanceof Model) {
            return $parent->getAttribute($attribute);
        }

        if (is_int($parent) || (is_string($parent) && ctype_digit($parent))) {
            return $parentModel::withoutGlobalScopes()->whereKey($parent)->value($attribute);
        }

        return null;
    }

    /**
     * Parents given through for() arrive as memoized resolver closures of BelongsToRelationship.
     */
    private function isForRelationshipResolver(mixed $parent): bool
    {
        return $parent instanceof Closure
            && (new ReflectionFunction($parent))->getClosureThis() instanceof BelongsToRelationship;
    }
}
