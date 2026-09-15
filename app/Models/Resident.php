<?php

namespace App\Models;

use App\Support\PhoneNumber;
use App\Support\Tenancy\BelongsToCondominium;
use Database\Factories\ResidentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property int $condominium_id
 * @property int $unit_id
 * @property int $resident_profile_id
 * @property string $name
 * @property-read string $first_name
 * @property string $phone
 * @property bool $is_active
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Unit $unit
 * @property-read ResidentProfile $residentProfile
 */
#[Fillable(['unit_id', 'resident_profile_id', 'name', 'phone', 'is_active'])]
class Resident extends Model
{
    /** @use HasFactory<ResidentFactory> */
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
        ];
    }

    /**
     * Store the phone normalized to E.164 whenever it is a valid number.
     *
     * @return Attribute<string, string>
     */
    protected function phone(): Attribute
    {
        return Attribute::set(fn (string $value): string => PhoneNumber::normalize($value) ?? $value);
    }

    /**
     * @return Attribute<string, never>
     */
    protected function firstName(): Attribute
    {
        return Attribute::get(fn (): string => Str::before(trim($this->name), ' '));
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
     * @return BelongsTo<Unit, $this>
     */
    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    /**
     * @return BelongsTo<ResidentProfile, $this>
     */
    public function residentProfile(): BelongsTo
    {
        return $this->belongsTo(ResidentProfile::class);
    }

    /**
     * @return HasMany<Ticket, $this>
     */
    public function tickets(): HasMany
    {
        return $this->hasMany(Ticket::class);
    }

    /**
     * @return HasMany<Reservation, $this>
     */
    public function reservations(): HasMany
    {
        return $this->hasMany(Reservation::class);
    }

    /**
     * @return HasMany<Escalation, $this>
     */
    public function escalations(): HasMany
    {
        return $this->hasMany(Escalation::class);
    }

    /**
     * @return HasMany<AgentToolCall, $this>
     */
    public function agentToolCalls(): HasMany
    {
        return $this->hasMany(AgentToolCall::class);
    }
}
