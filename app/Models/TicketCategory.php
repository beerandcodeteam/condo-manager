<?php

namespace App\Models;

use App\Support\Tenancy\BelongsToCondominium;
use Database\Factories\TicketCategoryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $condominium_id
 * @property string $name
 * @property string $slug
 * @property bool $is_active
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'slug', 'is_active'])]
class TicketCategory extends Model
{
    /** @use HasFactory<TicketCategoryFactory> */
    use BelongsToCondominium, HasFactory;

    /**
     * Categories seeded for every new condominium, keyed by slug.
     *
     * @var array<string, string>
     */
    public const DEFAULTS = [
        'eletrica' => 'Elétrica',
        'hidraulica' => 'Hidráulica',
        'elevador' => 'Elevador',
        'limpeza' => 'Limpeza',
        'seguranca' => 'Segurança',
        'outros' => 'Outros',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where($this->qualifyColumn('is_active'), true);
    }

    /**
     * @return HasMany<Ticket, $this>
     */
    public function tickets(): HasMany
    {
        return $this->hasMany(Ticket::class);
    }
}
