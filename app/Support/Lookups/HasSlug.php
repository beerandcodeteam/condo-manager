<?php

namespace App\Support\Lookups;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * Lookup models identified by a stable, seeded slug.
 */
trait HasSlug
{
    /**
     * Resolve the id of the lookup row with the given slug, memoized for the current request.
     *
     * @throws ModelNotFoundException
     */
    public static function idFor(string $slug): int
    {
        $lookupClass = static::class;

        return once(fn (): int => (int) $lookupClass::query()->slug($slug)->firstOrFail()->getKey());
    }

    /**
     * Filter the query by slug.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeSlug(Builder $query, string $slug): Builder
    {
        return $query->where($this->qualifyColumn('slug'), $slug);
    }
}
