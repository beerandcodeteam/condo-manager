<?php

namespace App\Models;

use App\Support\Tenancy\BelongsToCondominium;
use Database\Factories\WebhookDeliveryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $condominium_id
 * @property int $webhook_event_id
 * @property int $webhook_delivery_status_id
 * @property string $subject_type
 * @property int $subject_id
 * @property string|null $resident_phone
 * @property string $url
 * @property array<string, mixed> $payload
 * @property int $attempts
 * @property int|null $last_response_code
 * @property string|null $last_error
 * @property Carbon|null $delivered_at
 * @property Carbon|null $failed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read string $reference_label
 * @property-read Ticket|Reservation|Escalation|null $subject
 */
#[Fillable(['webhook_event_id', 'webhook_delivery_status_id', 'subject_type', 'subject_id', 'resident_phone', 'url', 'payload', 'attempts', 'last_response_code', 'last_error', 'delivered_at', 'failed_at'])]
class WebhookDelivery extends Model
{
    /** @use HasFactory<WebhookDeliveryFactory> */
    use BelongsToCondominium, HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'attempts' => 'integer',
            'last_response_code' => 'integer',
            'delivered_at' => 'datetime',
            'failed_at' => 'datetime',
        ];
    }

    /**
     * What the delivery is about: "Chamado #123", "Reserva · Churrasqueira · 27/09" or "Escalonamento #7".
     *
     * @return Attribute<non-falsy-string, never>
     */
    protected function referenceLabel(): Attribute
    {
        return Attribute::get(fn (): string => match (true) {
            $this->subject instanceof Ticket => "Chamado {$this->subject->protocol_label}",
            $this->subject instanceof Reservation => "Reserva · {$this->subject->area->name} · {$this->subject->date->format('d/m')}",
            $this->subject instanceof Escalation => "Escalonamento #{$this->subject->id}",
            default => '—',
        });
    }

    /**
     * Deliveries that failed after the last retry.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeFailed(Builder $query): Builder
    {
        return $query->where($this->qualifyColumn('webhook_delivery_status_id'), WebhookDeliveryStatus::idFor(WebhookDeliveryStatus::FALHOU));
    }

    /**
     * @return BelongsTo<WebhookEvent, $this>
     */
    public function event(): BelongsTo
    {
        return $this->belongsTo(WebhookEvent::class, 'webhook_event_id');
    }

    /**
     * @return BelongsTo<WebhookDeliveryStatus, $this>
     */
    public function status(): BelongsTo
    {
        return $this->belongsTo(WebhookDeliveryStatus::class, 'webhook_delivery_status_id');
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function subject(): MorphTo
    {
        return $this->morphTo();
    }
}
