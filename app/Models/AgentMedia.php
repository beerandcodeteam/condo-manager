<?php

namespace App\Models;

use App\Support\PhoneNumber;
use App\Support\Tenancy\BelongsToCondominium;
use Database\Factories\AgentMediaFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A file the resident sent over WhatsApp, uploaded by the n8n flow when it arrived.
 *
 * @property int $id
 * @property int $condominium_id
 * @property int $agent_message_kind_id
 * @property int|null $resident_id
 * @property int|null $ticket_id
 * @property string $phone
 * @property string $file_path
 * @property string $mime_type
 * @property int $size_bytes
 * @property string|null $caption
 * @property string|null $transcription
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read AgentMediaKind $kind
 * @property-read Resident|null $resident
 * @property-read Ticket|null $ticket
 */
#[Fillable(['agent_media_kind_id', 'resident_id', 'phone', 'file_path', 'mime_type', 'size_bytes', 'caption', 'transcription'])]
class AgentMedia extends Model
{
    /** @use HasFactory<AgentMediaFactory> */
    use BelongsToCondominium, HasFactory;

    protected $table = 'agent_media';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'size_bytes' => 'integer',
        ];
    }

    /**
     * @return Attribute<string, string>
     */
    protected function phone(): Attribute
    {
        return Attribute::set(fn (string $value): string => PhoneNumber::normalize($value) ?? $value);
    }

    /**
     * Media of this phone that no ticket consumed yet and is still recent enough to offer the agent.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopePending(Builder $query, string $phone): Builder
    {
        return $query
            ->where($this->qualifyColumn('phone'), $phone)
            ->whereNull($this->qualifyColumn('ticket_id'))
            ->where($this->qualifyColumn('created_at'), '>=', now()->subHours((int) config('condo.media.pending_hours')));
    }

    /**
     * @return BelongsTo<AgentMediaKind, $this>
     */
    public function kind(): BelongsTo
    {
        return $this->belongsTo(AgentMediaKind::class, 'agent_media_kind_id');
    }

    /**
     * @return BelongsTo<Resident, $this>
     */
    public function resident(): BelongsTo
    {
        return $this->belongsTo(Resident::class);
    }

    /**
     * @return BelongsTo<Ticket, $this>
     */
    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }
}
