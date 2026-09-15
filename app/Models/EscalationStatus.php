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
class EscalationStatus extends Model
{
    use HasSlug;

    public const PENDENTE = 'pendente';

    public const EM_ATENDIMENTO = 'em_atendimento';

    public const RESOLVIDO = 'resolvido';

    /**
     * @return HasMany<Escalation, $this>
     */
    public function escalations(): HasMany
    {
        return $this->hasMany(Escalation::class);
    }
}
