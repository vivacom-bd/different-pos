<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use App\Policies\Concerns\ChecksTenantPermissions;
use App\Support\Permissions\SystemRoles;

final class UserPolicy
{
    use ChecksTenantPermissions;

    public function viewAny(User $user): bool
    {
        return $this->allows($user, 'users.manage');
    }

    public function view(User $user, User $model): bool
    {
        return $this->allows($user, 'users.manage', $model);
    }

    public function create(User $user): bool
    {
        return $this->allows($user, 'users.manage');
    }

    public function update(User $user, User $model): bool
    {
        return $this->allows($user, 'users.manage', $model);
    }

    public function delete(User $user, User $model): bool
    {
        return $this->allows($user, 'users.manage', $model);
    }

    /** PIN resets are sensitive: only an Owner may reset another staff member's PIN. */
    public function resetPin(User $user, User $model): bool
    {
        if ($user->is($model)) {
            return $this->allows($user, 'users.manage', $model);
        }

        return $this->allows($user, 'users.manage', $model) && $user->hasRole(SystemRoles::OWNER);
    }

    /** Anyone may change their own UI preferences (theme, locale ...); nobody else's. */
    public function updatePreferences(User $user, User $model): bool
    {
        return $user->is($model) && $this->allows($user, [], $model);
    }
}
