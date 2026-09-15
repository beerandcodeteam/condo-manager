<?php

namespace App\Models;

use App\Support\Tenancy\BelongsToCondominium;
use Database\Factories\TicketFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property int $condominium_id
 * @property int $protocol_number
 * @property-read string $protocol_label
 * @property-read string $origin_label
 * @property-read string $unit_label
 * @property int $ticket_status_id
 * @property int $ticket_priority_id
 * @property int $ticket_origin_id
 * @property int|null $ticket_category_id
 * @property int|null $unit_id
 * @property int|null $resident_id
 * @property int|null $opened_by_user_id
 * @property string $description
 * @property string|null $location
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read TicketStatus $status
 * @property-read Resident|null $resident
 * @property-read Unit|null $unit
 */
#[Fillable(['protocol_number', 'ticket_status_id', 'ticket_priority_id', 'ticket_origin_id', 'ticket_category_id', 'unit_id', 'resident_id', 'opened_by_user_id', 'description', 'location'])]
class Ticket extends Model
{
    /** @use HasFactory<TicketFactory> */
    use BelongsToCondominium, HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'protocol_number' => 'integer',
        ];
    }

    /**
     * @return Attribute<non-empty-string, never>
     */
    protected function protocolLabel(): Attribute
    {
        return Attribute::get(fn (): string => Str::start((string) $this->protocol_number, '#'));
    }

    /**
     * Where the ticket came from: "WhatsApp · agente" or "Painel · <user name>".
     *
     * @return Attribute<non-falsy-string, never>
     */
    protected function originLabel(): Attribute
    {
        return Attribute::get(function (): string {
            if ($this->isFromWhatsapp()) {
                return 'WhatsApp · agente';
            }

            return $this->openedBy === null ? 'Painel' : "Painel · {$this->openedBy->name}";
        });
    }

    /**
     * Unit label, or "Área comum" when the ticket has no unit.
     *
     * @return Attribute<string, never>
     */
    protected function unitLabel(): Attribute
    {
        return Attribute::get(fn (): string => $this->unit === null ? 'Área comum' : $this->unit->label);
    }

    public function isFromWhatsapp(): bool
    {
        return $this->ticket_origin_id === TicketOrigin::idFor(TicketOrigin::WHATSAPP);
    }

    /**
     * Tickets still being worked on: aberto or em_andamento.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereIn($this->qualifyColumn('ticket_status_id'), [
            TicketStatus::idFor(TicketStatus::ABERTO),
            TicketStatus::idFor(TicketStatus::EM_ANDAMENTO),
        ]);
    }

    /**
     * Tickets in a final status: resolvido or cancelado.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeClosed(Builder $query): Builder
    {
        return $query->whereIn($this->qualifyColumn('ticket_status_id'), [
            TicketStatus::idFor(TicketStatus::RESOLVIDO),
            TicketStatus::idFor(TicketStatus::CANCELADO),
        ]);
    }

    public function isFinal(): bool
    {
        return $this->status->is_final;
    }

    /**
     * @return BelongsTo<TicketStatus, $this>
     */
    public function status(): BelongsTo
    {
        return $this->belongsTo(TicketStatus::class, 'ticket_status_id');
    }

    /**
     * @return BelongsTo<TicketPriority, $this>
     */
    public function priority(): BelongsTo
    {
        return $this->belongsTo(TicketPriority::class, 'ticket_priority_id');
    }

    /**
     * @return BelongsTo<TicketOrigin, $this>
     */
    public function origin(): BelongsTo
    {
        return $this->belongsTo(TicketOrigin::class, 'ticket_origin_id');
    }

    /**
     * @return BelongsTo<TicketCategory, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(TicketCategory::class, 'ticket_category_id');
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
     * @return BelongsTo<User, $this>
     */
    public function openedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'opened_by_user_id');
    }

    /**
     * @return HasMany<TicketPhoto, $this>
     */
    public function photos(): HasMany
    {
        return $this->hasMany(TicketPhoto::class);
    }

    /**
     * @return HasMany<TicketStatusChange, $this>
     */
    public function statusChanges(): HasMany
    {
        return $this->hasMany(TicketStatusChange::class);
    }

    /**
     * @return HasMany<TicketResidentNotice, $this>
     */
    public function residentNotices(): HasMany
    {
        return $this->hasMany(TicketResidentNotice::class);
    }

    /**
     * @return MorphMany<WebhookDelivery, $this>
     */
    public function webhookDeliveries(): MorphMany
    {
        return $this->morphMany(WebhookDelivery::class, 'subject');
    }
}
