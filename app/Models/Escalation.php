<?php

namespace App\Models;

use App\Support\Tenancy\BelongsToCondominium;
use Database\Factories\EscalationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $condominium_id
 * @property int $resident_id
 * @property int $unit_id
 * @property int|null $ticket_id
 * @property int $escalation_status_id
 * @property int $escalation_reason_id
 * @property string $summary
 * @property int|null $assigned_user_id
 * @property Carbon|null $assigned_at
 * @property string|null $response
 * @property int|null $responded_by_user_id
 * @property Carbon|null $resolved_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Resident $resident
 * @property-read Unit $unit
 */
#[Fillable(['resident_id', 'unit_id', 'ticket_id', 'escalation_status_id', 'escalation_reason_id', 'summary', 'assigned_user_id', 'assigned_at', 'response', 'responded_by_user_id', 'resolved_at'])]
class Escalation extends Model
{
    /** @use HasFactory<EscalationFactory> */
    use BelongsToCondominium, HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'assigned_at' => 'datetime',
            'resolved_at' => 'datetime',
        ];
    }

    /**
     * Escalations still waiting for a human: pendente or em_atendimento.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeUnresolved(Builder $query): Builder
    {
        return $query->where($this->qualifyColumn('escalation_status_id'), '!=', EscalationStatus::idFor(EscalationStatus::RESOLVIDO));
    }

    public function hasStatus(string $statusSlug): bool
    {
        return $this->escalation_status_id === EscalationStatus::idFor($statusSlug);
    }

    /**
     * @return BelongsTo<Resident, $this>
     */
    public function resident(): BelongsTo
    {
        return $this->belongsTo(Resident::class);
    }

    /**
     * @return BelongsTo<Unit, $this>
     */
    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    /**
     * @return BelongsTo<Ticket, $this>
     */
    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }

    /**
     * @return BelongsTo<EscalationStatus, $this>
     */
    public function status(): BelongsTo
    {
        return $this->belongsTo(EscalationStatus::class, 'escalation_status_id');
    }

    /**
     * @return BelongsTo<EscalationReason, $this>
     */
    public function reason(): BelongsTo
    {
        return $this->belongsTo(EscalationReason::class, 'escalation_reason_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function assignedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_user_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function respondedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responded_by_user_id');
    }

    /**
     * @return HasMany<EscalationAssignment, $this>
     */
    public function assignments(): HasMany
    {
        return $this->hasMany(EscalationAssignment::class);
    }

    /**
     * @return MorphMany<WebhookDelivery, $this>
     */
    public function webhookDeliveries(): MorphMany
    {
        return $this->morphMany(WebhookDelivery::class, 'subject');
    }
}
