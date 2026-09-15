<?php

namespace App\Models;

use App\Support\Tenancy\BelongsToCondominium;
use Carbon\CarbonImmutable;
use Database\Factories\ReservationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $condominium_id
 * @property int $common_area_id
 * @property int $common_area_slot_id
 * @property int $unit_id
 * @property int $resident_id
 * @property int $reservation_status_id
 * @property int $reservation_origin_id
 * @property int|null $created_by_user_id
 * @property CarbonImmutable $date
 * @property string $starts_at
 * @property string $ends_at
 * @property CarbonImmutable|null $cancelled_at
 * @property int|null $reservation_cancellation_origin_id
 * @property string|null $cancellation_reason
 * @property int|null $cancelled_by_user_id
 * @property-read string $starts
 * @property-read string $ends
 * @property-read string $hour_range
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read CommonAreaSlot $slot
 * @property-read Resident $resident
 * @property-read Unit $unit
 * @property-read CommonArea $area
 */
#[Fillable(['common_area_id', 'common_area_slot_id', 'unit_id', 'resident_id', 'reservation_status_id', 'reservation_origin_id', 'created_by_user_id', 'date', 'starts_at', 'ends_at', 'cancelled_at', 'reservation_cancellation_origin_id', 'cancellation_reason', 'cancelled_by_user_id'])]
class Reservation extends Model
{
    /** @use HasFactory<ReservationFactory> */
    use BelongsToCondominium, HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'date' => 'date',
            'cancelled_at' => 'datetime',
        ];
    }

    /**
     * Reservations that were not cancelled.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull($this->qualifyColumn('cancelled_at'));
    }

    /**
     * Reservations from today on, where "today" is resolved in the condominium timezone.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeUpcoming(Builder $query): Builder
    {
        return $query->whereDate($this->qualifyColumn('date'), '>=', CarbonImmutable::now(config('condo.timezone'))->toDateString());
    }

    /**
     * Whether the reservation still holds its slot (`confirmada` and not cancelled).
     */
    public function isConfirmed(): bool
    {
        return $this->cancelled_at === null && $this->reservation_status_id === ReservationStatus::idFor(ReservationStatus::CONFIRMADA);
    }

    /**
     * @return Attribute<string, never>
     */
    protected function starts(): Attribute
    {
        return Attribute::get(fn (): string => CommonAreaSlot::shortTime((string) $this->starts_at));
    }

    /**
     * @return Attribute<string, never>
     */
    protected function ends(): Attribute
    {
        return Attribute::get(fn (): string => CommonAreaSlot::shortTime((string) $this->ends_at));
    }

    /**
     * Compact hour range of the snapshot times, e.g. "19h–23h".
     *
     * @return Attribute<string, never>
     */
    protected function hourRange(): Attribute
    {
        return Attribute::get(fn (): string => CommonAreaSlot::formatHourRange((string) $this->starts_at, (string) $this->ends_at));
    }

    /**
     * The reservation date combined with its start time, in the condominium timezone.
     */
    public function startsAtInCondoTimezone(): CarbonImmutable
    {
        return CarbonImmutable::parse("{$this->date->format('Y-m-d')} {$this->starts_at}", config('condo.timezone'));
    }

    /**
     * @return BelongsTo<CommonArea, $this>
     */
    public function area(): BelongsTo
    {
        return $this->belongsTo(CommonArea::class, 'common_area_id');
    }

    /**
     * The slot is kept even after being soft deleted, so past reservations still resolve it.
     *
     * @return BelongsTo<CommonAreaSlot, $this>
     */
    public function slot(): BelongsTo
    {
        return $this->belongsTo(CommonAreaSlot::class, 'common_area_slot_id')->withTrashed();
    }

    /**
     * @return BelongsTo<Unit, $this>
     */
    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    /**
     * @return BelongsTo<Resident, $this>
     */
    public function resident(): BelongsTo
    {
        return $this->belongsTo(Resident::class);
    }

    /**
     * @return BelongsTo<ReservationStatus, $this>
     */
    public function status(): BelongsTo
    {
        return $this->belongsTo(ReservationStatus::class, 'reservation_status_id');
    }

    /**
     * @return BelongsTo<ReservationOrigin, $this>
     */
    public function origin(): BelongsTo
    {
        return $this->belongsTo(ReservationOrigin::class, 'reservation_origin_id');
    }

    /**
     * @return BelongsTo<ReservationCancellationOrigin, $this>
     */
    public function cancellationOrigin(): BelongsTo
    {
        return $this->belongsTo(ReservationCancellationOrigin::class, 'reservation_cancellation_origin_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function cancelledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by_user_id');
    }

    /**
     * @return MorphMany<WebhookDelivery, $this>
     */
    public function webhookDeliveries(): MorphMany
    {
        return $this->morphMany(WebhookDelivery::class, 'subject');
    }
}
