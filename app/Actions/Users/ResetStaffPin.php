<?php

declare(strict_types=1);

namespace App\Actions\Users;

use App\Exceptions\Domain\InvalidAdminData;
use App\Models\User;
use App\Support\Audit\Audit;
use App\Support\Database\Transaction;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;

/**
 * Sets a staff member's register PIN (4–6 digits, argon2id) and clears any PIN lockout (04_TRD §3.8 #2).
 * Needs users.manage; resetting another member's PIN needs the Owner role (UserPolicy::resetPin).
 * The PIN itself is never logged or audited: the audit row only says it was reset.
 */
final readonly class ResetStaffPin
{
    public const string PATTERN = '/^\d{4,6}$/';

    public function handle(User $actor, User $user, string $pin): User
    {
        if (preg_match(self::PATTERN, $pin) !== 1) {
            throw InvalidAdminData::invalid('The PIN must be 4 to 6 digits.');
        }

        return Transaction::run(function () use ($actor, $user, $pin): User {
            $user = User::query()->inCurrentTenant()->lockForUpdate()->findOrFail($user->id);
            Gate::forUser($actor)->authorize('resetPin', $user);

            $user->forceFill([
                'pin_hash' => Hash::make($pin),
                'pin_failed_attempts' => 0,
                'pin_locked_until' => null,
            ])->save();

            Audit::log('pin.reset', $user, ['scope' => 'staff'], $actor);

            return $user;
        });
    }
}
