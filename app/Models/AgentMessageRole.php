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
class AgentMessageRole extends Model
{
    use HasSlug;

    public const MORADOR = 'morador';

    public const AGENTE = 'agente';

    /**
     * @return HasMany<AgentMessage, $this>
     */
    public function messages(): HasMany
    {
        return $this->hasMany(AgentMessage::class);
    }
}
