<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property string $name
 * @property string $email
 * @property Carbon|null $email_verified_at
 * @property string $password
 * @property string|null $remember_token
 * @property int $role_id
 * @property int|null $condominium_id
 * @property bool $is_active
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Role $role
 * @property-read Condominium|null $condominium
 */
#[Fillable(['name', 'email', 'password', 'role_id', 'condominium_id', 'is_active'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
        ];
    }

    /**
     * Get the user's initials
     */
    public function initials(): string
    {
        $initials = Str::initials($this->name, true);

        return Str::length($initials) > 1
            ? Str::substr($initials, 0, 1).Str::substr($initials, -1)
            : $initials;
    }

    public function isSuperAdmin(): bool
    {
        return $this->role_id === Role::idFor(Role::SUPER_ADMIN);
    }

    public function isSindico(): bool
    {
        return $this->role_id === Role::idFor(Role::SINDICO);
    }

    public function isZelador(): bool
    {
        return $this->role_id === Role::idFor(Role::ZELADOR);
    }

    public function hasAnyRole(string ...$roleSlugs): bool
    {
        return collect($roleSlugs)->contains(fn (string $roleSlug): bool => $this->role_id === Role::idFor($roleSlug));
    }

    /**
     * @return BelongsTo<Role, $this>
     */
    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    /**
     * @return BelongsTo<Condominium, $this>
     */
    public function condominium(): BelongsTo
    {
        return $this->belongsTo(Condominium::class);
    }

    /**
     * Escalations this user is currently responsible for.
     *
     * @return HasMany<Escalation, $this>
     */
    public function assignedEscalations(): HasMany
    {
        return $this->hasMany(Escalation::class, 'assigned_user_id');
    }

    /**
     * @return HasMany<EscalationAssignment, $this>
     */
    public function escalationAssignments(): HasMany
    {
        return $this->hasMany(EscalationAssignment::class);
    }

    /**
     * @return HasMany<TicketStatusChange, $this>
     */
    public function ticketStatusChanges(): HasMany
    {
        return $this->hasMany(TicketStatusChange::class);
    }

    /**
     * @return HasMany<TicketResidentNotice, $this>
     */
    public function ticketResidentNotices(): HasMany
    {
        return $this->hasMany(TicketResidentNotice::class);
    }

    /**
     * @return HasMany<RuleDocument, $this>
     */
    public function uploadedRuleDocuments(): HasMany
    {
        return $this->hasMany(RuleDocument::class, 'uploaded_by_user_id');
    }

    /**
     * @return HasMany<Notice, $this>
     */
    public function createdNotices(): HasMany
    {
        return $this->hasMany(Notice::class, 'created_by_user_id');
    }

    /**
     * @return HasMany<Ticket, $this>
     */
    public function openedTickets(): HasMany
    {
        return $this->hasMany(Ticket::class, 'opened_by_user_id');
    }

    /**
     * @return HasMany<Reservation, $this>
     */
    public function createdReservations(): HasMany
    {
        return $this->hasMany(Reservation::class, 'created_by_user_id');
    }
}
