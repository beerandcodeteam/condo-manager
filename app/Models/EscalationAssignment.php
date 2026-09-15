<?php

namespace App\Models;

use App\Support\Tenancy\BelongsToCondominium;
use Database\Factories\EscalationAssignmentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $condominium_id
 * @property int $escalation_id
 * @property int $user_id
 * @property int|null $previous_user_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['escalation_id', 'user_id', 'previous_user_id'])]
class EscalationAssignment extends Model
{
    /** @use HasFactory<EscalationAssignmentFactory> */
    use BelongsToCondominium, HasFactory;

    /**
     * @return BelongsTo<Escalation, $this>
     */
    public function escalation(): BelongsTo
    {
        return $this->belongsTo(Escalation::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function previousUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'previous_user_id');
    }
}
