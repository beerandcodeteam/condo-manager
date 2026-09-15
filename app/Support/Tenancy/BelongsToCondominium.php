<?php

namespace App\Support\Tenancy;

use App\Models\Condominium;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Tenant-owned models: scoped to the current condominium and filled with it on creation.
 *
 * @property int $condominium_id
 * @property-read Condominium $condominium
 */
trait BelongsToCondominium
{
    public static function bootBelongsToCondominium(): void
    {
        static::addGlobalScope(new CondominiumScope);

        static::creating(function (Model $model): void {
            $currentCondominiumId = app(CurrentCondominium::class)->id();

            if (blank($model->getAttribute('condominium_id')) && $currentCondominiumId !== null) {
                $model->setAttribute('condominium_id', $currentCondominiumId);
            }
        });
    }

    /**
     * @return BelongsTo<Condominium, $this>
     */
    public function condominium(): BelongsTo
    {
        return $this->belongsTo(Condominium::class);
    }
}
