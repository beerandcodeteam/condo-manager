<?php

namespace App\Models;

use App\Support\Tenancy\BelongsToCondominium;
use Database\Factories\CommonAreaFactory;
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
 * @property string|null $description
 * @property bool $is_active
 * @property int $min_advance_hours
 * @property int $max_advance_days
 * @property int $cancellation_deadline_hours
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'description', 'is_active', 'min_advance_hours', 'max_advance_days', 'cancellation_deadline_hours'])]
class CommonArea extends Model
{
    /** @use HasFactory<CommonAreaFactory> */
    use BelongsToCondominium, HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'min_advance_hours' => 'integer',
            'max_advance_days' => 'integer',
            'cancellation_deadline_hours' => 'integer',
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
     * @return HasMany<CommonAreaSlot, $this>
     */
    public function slots(): HasMany
    {
        return $this->hasMany(CommonAreaSlot::class)->orderBy('starts_at');
    }

    /**
     * @return HasMany<Reservation, $this>
     */
    public function reservations(): HasMany
    {
        return $this->hasMany(Reservation::class);
    }
}
