<?php

namespace App\Models;

use App\Support\Tenancy\BelongsToCondominium;
use Database\Factories\CommonAreaSlotFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $condominium_id
 * @property int $common_area_id
 * @property string $starts_at
 * @property string $ends_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read string $starts
 * @property-read string $ends
 * @property-read string $hour_range
 */
#[Fillable(['common_area_id', 'starts_at', 'ends_at'])]
class CommonAreaSlot extends Model
{
    /** @use HasFactory<CommonAreaSlotFactory> */
    use BelongsToCondominium, HasFactory, SoftDeletes;

    /**
     * Time as `HH:MM`, e.g. "17:00" for "17:00:00".
     */
    public static function shortTime(string $time): string
    {
        return substr($time, 0, 5);
    }

    /**
     * Compact hour range of the panel, e.g. "19h–23h" or "09h30–10h30".
     */
    public static function formatHourRange(string $startsAt, string $endsAt): string
    {
        $format = function (string $time): string {
            [$hours, $minutes] = explode(':', self::shortTime($time));

            return $minutes === '00' ? "{$hours}h" : "{$hours}h{$minutes}";
        };

        return $format($startsAt).'–'.$format($endsAt);
    }

    /**
     * @return Attribute<string, never>
     */
    protected function starts(): Attribute
    {
        return Attribute::get(fn (): string => self::shortTime((string) $this->starts_at));
    }

    /**
     * @return Attribute<string, never>
     */
    protected function ends(): Attribute
    {
        return Attribute::get(fn (): string => self::shortTime((string) $this->ends_at));
    }

    /**
     * @return Attribute<string, never>
     */
    protected function hourRange(): Attribute
    {
        return Attribute::get(fn (): string => self::formatHourRange((string) $this->starts_at, (string) $this->ends_at));
    }

    /**
     * @return BelongsTo<CommonArea, $this>
     */
    public function area(): BelongsTo
    {
        return $this->belongsTo(CommonArea::class, 'common_area_id');
    }

    /**
     * @return HasMany<Reservation, $this>
     */
    public function reservations(): HasMany
    {
        return $this->hasMany(Reservation::class);
    }
}
