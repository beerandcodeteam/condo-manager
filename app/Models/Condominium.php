<?php

namespace App\Models;

use Database\Factories\CondominiumFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\HasApiTokens;

/**
 * The tenant. API tokens (Sanctum) belong to the condominium, not to a user.
 *
 * @property int $id
 * @property string $name
 * @property string|null $city
 * @property string|null $whatsapp_number
 * @property string|null $caretaker_name
 * @property string|null $caretaker_phone
 * @property string|null $quiet_hours_start
 * @property string|null $quiet_hours_end
 * @property string|null $webhook_url
 * @property string|null $webhook_secret
 * @property int $last_ticket_protocol
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Table('condominiums')]
#[Fillable(['name', 'city', 'whatsapp_number', 'caretaker_name', 'caretaker_phone', 'quiet_hours_start', 'quiet_hours_end', 'webhook_url', 'webhook_secret'])]
#[Hidden(['webhook_secret'])]
class Condominium extends Model
{
    /** @use HasFactory<CondominiumFactory> */
    use HasApiTokens, HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'webhook_secret' => 'encrypted',
            'last_ticket_protocol' => 'integer',
        ];
    }

    public function hasWebhook(): bool
    {
        return filled($this->webhook_url);
    }

    /**
     * @return HasMany<User, $this>
     */
    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    /**
     * @return HasMany<Block, $this>
     */
    public function blocks(): HasMany
    {
        return $this->hasMany(Block::class);
    }

    /**
     * @return HasMany<Unit, $this>
     */
    public function units(): HasMany
    {
        return $this->hasMany(Unit::class);
    }

    /**
     * @return HasMany<Resident, $this>
     */
    public function residents(): HasMany
    {
        return $this->hasMany(Resident::class);
    }

    /**
     * @return HasMany<TicketCategory, $this>
     */
    public function ticketCategories(): HasMany
    {
        return $this->hasMany(TicketCategory::class);
    }

    /**
     * @return HasMany<RuleDocument, $this>
     */
    public function ruleDocuments(): HasMany
    {
        return $this->hasMany(RuleDocument::class);
    }

    /**
     * @return HasMany<Notice, $this>
     */
    public function notices(): HasMany
    {
        return $this->hasMany(Notice::class);
    }

    /**
     * @return HasMany<Ticket, $this>
     */
    public function tickets(): HasMany
    {
        return $this->hasMany(Ticket::class);
    }

    /**
     * @return HasMany<CommonArea, $this>
     */
    public function commonAreas(): HasMany
    {
        return $this->hasMany(CommonArea::class);
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
     * @return HasMany<WebhookDelivery, $this>
     */
    public function webhookDeliveries(): HasMany
    {
        return $this->hasMany(WebhookDelivery::class);
    }

    /**
     * @return HasMany<AgentToolCall, $this>
     */
    public function agentToolCalls(): HasMany
    {
        return $this->hasMany(AgentToolCall::class);
    }
}
