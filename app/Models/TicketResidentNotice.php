<?php

namespace App\Models;

use App\Support\Tenancy\BelongsToCondominium;
use Database\Factories\TicketResidentNoticeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $condominium_id
 * @property int $ticket_id
 * @property int $user_id
 * @property string $message
 * @property int|null $webhook_delivery_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['ticket_id', 'user_id', 'message', 'webhook_delivery_id'])]
class TicketResidentNotice extends Model
{
    /** @use HasFactory<TicketResidentNoticeFactory> */
    use BelongsToCondominium, HasFactory;

    /**
     * @return BelongsTo<Ticket, $this>
     */
    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<WebhookDelivery, $this>
     */
    public function webhookDelivery(): BelongsTo
    {
        return $this->belongsTo(WebhookDelivery::class);
    }
}
