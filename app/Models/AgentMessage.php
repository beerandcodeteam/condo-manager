<?php

namespace App\Models;

use App\Support\PhoneNumber;
use App\Support\Tenancy\BelongsToCondominium;
use Database\Factories\AgentMessageFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One turn of a WhatsApp conversation, written by the n8n flow after the agent answers.
 *
 * @property int $id
 * @property int $condominium_id
 * @property int $agent_message_role_id
 * @property int|null $resident_id
 * @property string $phone
 * @property string $content
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read AgentMessageRole $role
 * @property-read Resident|null $resident
 */
#[Fillable(['agent_message_role_id', 'resident_id', 'phone', 'content'])]
class AgentMessage extends Model
{
    /** @use HasFactory<AgentMessageFactory> */
    use BelongsToCondominium, HasFactory;

    /**
     * Store the phone normalized to E.164 whenever it is a valid number, like Resident does.
     *
     * @return Attribute<string, string>
     */
    protected function phone(): Attribute
    {
        return Attribute::set(fn (string $value): string => PhoneNumber::normalize($value) ?? $value);
    }

    /**
     * The conversation of one WhatsApp number, oldest first.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeForPhone(Builder $query, string $phone): Builder
    {
        return $query->where($this->qualifyColumn('phone'), $phone);
    }

    /**
     * @return BelongsTo<AgentMessageRole, $this>
     */
    public function role(): BelongsTo
    {
        return $this->belongsTo(AgentMessageRole::class, 'agent_message_role_id');
    }

    /**
     * @return BelongsTo<Resident, $this>
     */
    public function resident(): BelongsTo
    {
        return $this->belongsTo(Resident::class);
    }
}
