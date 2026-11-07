<?php

declare(strict_types=1);

namespace App\Actions\Users;

use App\Exceptions\Domain\InvalidAdminData;
use App\Models\User;
use App\Support\Audit\Audit;
use App\Support\Database\Transaction;
use App\Support\Permissions\SystemRoles;
use Spatie\Permission\PermissionRegistrar;

/** Deactivates a user: no more sign-in, and an open session ends on its next request. Never yourself, never the last Owner. */
final readonly class DeactivateUser
{
    public function handle(User $actor, User $user): User
    {
        return Transaction::run(function () use ($actor, $user): User {
            $user = User::query()->inCurrentTenant()->findOrFail($user->id);
            app(PermissionRegistrar::class)->setPermissionsTeamId($user->tenant_id);

            if ($actor->is($user)) {
                throw InvalidAdminData::invalid('You cannot deactivate your own account.');
            }
            if ($user->hasRole(SystemRoles::OWNER)
                && ! User::query()->inCurrentTenant()->where('is_active', true)->whereKeyNot($user->id)->role(SystemRoles::OWNER)->exists()) {
                throw InvalidAdminData::invalid('The business needs at least one active Owner.');
            }
            if ($user->checkPermissionTo('users.manage')
                && ! User::query()->inCurrentTenant()->where('is_active', true)->whereKeyNot($user->id)->whereHas('roles.permissions', fn ($q) => $q->where('name', 'users.manage'))->exists()) {
                throw InvalidAdminData::invalid('The business needs at least one active user with users.manage.');
            }

            $user->forceFill(['is_active' => false, 'remember_token' => null])->save();
            Audit::log('user.deactivated', $user, [], $actor);

            return $user;
        });
    }
}
