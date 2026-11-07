<?php

declare(strict_types=1);

namespace App\Actions\Users;

use App\Models\Branch;
use App\Models\User;
use App\Support\Database\Transaction;
use Illuminate\Support\Facades\Cache;

/**
 * Remembers the branch the user last worked in (users.preferences.last_branch_id)
 * and drops the new branch's cached dashboard/ticker so the first page after a
 * switch never shows the old branch-of-day numbers. The session part is the controller's.
 */
final readonly class SwitchBranch
{
    public function handle(User $user, Branch $branch): void
    {
        Transaction::run(function () use ($user, $branch): void {
            $user->forceFill([
                'preferences' => [...($user->preferences ?? []), 'last_branch_id' => $branch->id],
            ])->save();
            Cache::forget("today-ticker:{$user->tenant_id}:{$branch->id}:".now()->format('Y-m-d'));
        });
    }
}
