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
class WebhookEvent extends Model
{
    use HasSlug;

    public const TICKET_STATUS_CHANGED = 'ticket.status_changed';

    public const TICKET_RESIDENT_NOTIFIED = 'ticket.resident_notified';

    public const RESERVATION_CANCELLED = 'reservation.cancelled';

    public const ESCALATION_ANSWERED = 'escalation.answered';

    /**
     * @return HasMany<WebhookDelivery, $this>
     */
    public function webhookDeliveries(): HasMany
    {
        return $this->hasMany(WebhookDelivery::class);
    }
}
