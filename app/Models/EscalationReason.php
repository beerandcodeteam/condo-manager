<?php

namespace App\Models;

use App\Support\Lookups\HasSlug;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $name
 * @property string $slug
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'slug'])]
class EscalationReason extends Model
{
    use HasSlug;

    public const PEDIU_HUMANO = 'pediu_humano';

    public const TOOL_RECUSOU = 'tool_recusou';

    public const SEM_REGRA = 'sem_regra';

    /**
     * @return HasMany<Escalation, $this>
     */
    public function escalations(): HasMany
    {
        return $this->hasMany(Escalation::class);
    }
}
