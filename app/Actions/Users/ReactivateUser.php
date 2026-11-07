<?php

declare(strict_types=1);

namespace App\Actions\Users;

use App\Exceptions\Domain\InvalidAdminData;
use App\Models\User;
use App\Support\Audit\Audit;
use App\Support\Database\Transaction;
use Spatie\Permission\PermissionRegistrar;

/** Reactivates a previously deactivated user. Same tenant only; audit `user.reactivated`. */
final readonly class ReactivateUser
{
    public function handle(User $actor, User $user): User
    {
        return Transaction::run(function () use ($actor, $user): User {
            $user = User::query()->inCurrentTenant()->findOrFail($user->id);
            app(PermissionRegistrar::class)->setPermissionsTeamId($user->tenant_id);

            if ($actor->is($user) && $user->is_active) {
                throw InvalidAdminData::invalid('You are already active.');
            }
            if ($user->is_active) {
                throw InvalidAdminData::invalid('This user is already active.');
            }

            $user->forceFill(['is_active' => true])->save();
            Audit::log('user.reactivated', $user, [], $actor);

            return $user;
        });
    }
}
