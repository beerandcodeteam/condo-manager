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
 * @property bool $is_final
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'slug', 'is_final'])]
class TicketStatus extends Model
{
    use HasSlug;

    public const ABERTO = 'aberto';

    public const EM_ANDAMENTO = 'em_andamento';

    public const RESOLVIDO = 'resolvido';

    public const CANCELADO = 'cancelado';

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_final' => 'boolean',
        ];
    }

    /**
     * @return HasMany<Ticket, $this>
     */
    public function tickets(): HasMany
    {
        return $this->hasMany(Ticket::class);
    }

    /**
     * @return HasMany<TicketStatusChange, $this>
     */
    public function statusChangesFrom(): HasMany
    {
        return $this->hasMany(TicketStatusChange::class, 'from_ticket_status_id');
    }

    /**
     * @return HasMany<TicketStatusChange, $this>
     */
    public function statusChangesTo(): HasMany
    {
        return $this->hasMany(TicketStatusChange::class, 'to_ticket_status_id');
    }
}
