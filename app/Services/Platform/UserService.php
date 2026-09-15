<?php

namespace App\Services\Platform;

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Síndicos and zeladores managed by the super admin. Super admins are never managed here.
 */
class UserService
{
    /**
     * Roles that can be assigned on the platform user screen.
     *
     * @var list<string>
     */
    public const ASSIGNABLE_ROLES = [Role::SINDICO, Role::ZELADOR];

    /**
     * Users manageable on the platform screen (síndicos and zeladores).
     *
     * @return Builder<User>
     */
    public function manageable(): Builder
    {
        return User::query()->whereIn('role_id', array_map(Role::idFor(...), self::ASSIGNABLE_ROLES));
    }

    /**
     * @param  array{name: string, email: string, role: string, condominium_id: int, password: string}  $attributes
     */
    public function create(array $attributes): User
    {
        return User::create([
            'name' => $attributes['name'],
            'email' => $attributes['email'],
            'role_id' => $this->roleId($attributes['role']),
            'condominium_id' => $attributes['condominium_id'],
            'password' => $attributes['password'],
            'is_active' => true,
        ]);
    }

    /**
     * @param  array{name: string, email: string, role: string, condominium_id: int}  $attributes
     */
    public function update(User $user, array $attributes): User
    {
        $user->update([
            'name' => $attributes['name'],
            'email' => $attributes['email'],
            'role_id' => $this->roleId($attributes['role']),
            'condominium_id' => $attributes['condominium_id'],
        ]);

        return $user;
    }

    /**
     * Activate or deactivate the user; a deactivated user can no longer log in and loses active sessions.
     */
    public function setActive(User $user, bool $isActive): User
    {
        $user->update(['is_active' => $isActive]);

        return $user;
    }

    private function roleId(string $roleSlug): int
    {
        abort_unless(in_array($roleSlug, self::ASSIGNABLE_ROLES, true), 422);

        return Role::idFor($roleSlug);
    }
}
