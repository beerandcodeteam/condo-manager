<?php

namespace App\Support\Tenancy;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Restricts queries to the current condominium; applies no filter when there is none.
 *
 * @implements Scope<Model>
 */
class CondominiumScope implements Scope
{
    /**
     * @param  Builder<covariant Model>  $builder
     */
    public function apply(Builder $builder, Model $model): void
    {
        $condominiumId = app(CurrentCondominium::class)->id();

        if ($condominiumId !== null) {
            $builder->where($model->qualifyColumn('condominium_id'), $condominiumId);
        }
    }
}
