<?php

declare(strict_types=1);

namespace App\Actions\Users;

use App\Exceptions\Domain\InvalidAdminData;
use App\Models\Branch;
use App\Models\User;
use App\Support\Audit\Audit;
use App\Support\Database\Transaction;
use App\Support\Permissions\SystemRoles;
use App\Support\Phone\BdPhone;
use App\Support\Tenancy\TenantContext;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/** Creates or updates a staff user of the current tenant, with their roles, branches and (optionally) a new register PIN. */
final readonly class SaveUser
{
    public function __construct(private ResetStaffPin $pins) {}

    /**
     * @param  array{name?: string, username?: string|null, email?: string|null, phone?: string|null, password?: string|null, pin?: string|null, is_active?: bool, must_change_password?: bool, role_ids?: list<int>, branch_ids?: list<int>}  $data
     */
    public function handle(User $actor, array $data, ?User $user = null): User
    {
        return Transaction::run(function () use ($actor, $data, $user): User {
            $tenantId = app(TenantContext::class)->tenantId();
            app(PermissionRegistrar::class)->setPermissionsTeamId($tenantId);

            $user = $user instanceof User ? User::query()->inCurrentTenant()->findOrFail($user->id) : null;
            $created = ! $user instanceof User;
            $user ??= new User;

            $name = trim((string) ($data['name'] ?? $user->name ?? ''));
            if ($name === '') {
                throw InvalidAdminData::required('Name');
            }

            $email = array_key_exists('email', $data) ? $this->blank($data['email']) : $user->email;
            $username = array_key_exists('username', $data) ? $this->blank($data['username']) : $user->username;
            $phone = array_key_exists('phone', $data) ? $this->phone($data['phone']) : $user->phone;
            if ($email === null && $username === null && $phone === null) {
                throw InvalidAdminData::invalid('Give the user an email, a username or a phone number to sign in with.');
            }

            $password = $this->blank($data['password'] ?? null);
            if ($created && $password === null) {
                throw InvalidAdminData::required('Password');
            }

            $user->fill(['name' => $name, 'username' => $username, 'email' => $email !== null ? mb_strtolower($email) : null, 'phone' => $phone]);
            if ($created) {
                $user->forceFill(['tenant_id' => $tenantId, 'email_verified_at' => now()]);
            }
            if ($password !== null) {
                $user->password = $password;
                $user->legacy_password_md5 = null;
            }
            foreach (['is_active', 'must_change_password'] as $flag) {
                if (array_key_exists($flag, $data)) {
                    $user->{$flag} = (bool) $data[$flag];
                }
            }

            if (! $created && $user->isDirty('is_active') && ! $user->is_active) {
                $this->guardDeactivation($actor, $user);
            }
            $user->save();

            $roleNames = $this->syncRoles($actor, $user, $data['role_ids'] ?? null);
            $branchCodes = $this->syncBranches($user, $data['branch_ids'] ?? null);

            Audit::log($created ? 'user.created' : 'user.updated', $user, array_filter(['roles' => $roleNames, 'branches' => $branchCodes], static fn ($v): bool => $v !== null), $actor);

            $pin = $this->blank($data['pin'] ?? null);
            if ($pin !== null) {
                $this->pins->handle($actor, $user, $pin);
            }

            return $user;
        });
    }

    /** @return list<string>|null */
    private function syncRoles(User $actor, User $user, mixed $roleIds): ?array
    {
        if ($roleIds === null) {
            return null;
        }

        $ids = array_values(array_unique(array_map(intval(...), (array) $roleIds)));
        $roles = Role::query()->where('tenant_id', $user->tenant_id)->whereIn('id', $ids)->get();
        if ($roles->count() !== count($ids)) {
            throw InvalidAdminData::invalid('One of the selected roles does not exist.');
        }

        $owner = $roles->firstWhere('name', SystemRoles::OWNER);
        $hadOwner = $user->hasRole(SystemRoles::OWNER);
        if (($owner instanceof Role && ! $hadOwner) || ($hadOwner && $owner === null)) {
            if (! $actor->hasRole(SystemRoles::OWNER)) {
                throw InvalidAdminData::invalid('Only an Owner can grant or remove the Owner role.');
            }
        }
        if ($hadOwner && $owner === null && ! $this->otherActiveOwnerExists($user)) {
            throw InvalidAdminData::invalid('The business needs at least one active Owner.');
        }
        $hadUsersManage = $user->checkPermissionTo('users.manage');
        $hasUsersManage = $roles->contains(function ($r): bool {
            $names = $r->permissions->pluck('name')->map(strval(...))->all();

            return in_array('users.manage', $names, true);
        });
        if ($hadUsersManage && ! $hasUsersManage && ! $this->otherActiveUsersManageHolderExists($user)) {
            throw InvalidAdminData::invalid('The business needs at least one active user with users.manage.');
        }

        $user->syncRoles($roles->all());

        return array_values($roles->pluck('name')->map(strval(...))->all());
    }

    /** @return list<string>|null */
    private function syncBranches(User $user, mixed $branchIds): ?array
    {
        if ($branchIds === null) {
            return null;
        }

        $ids = array_values(array_unique(array_map(intval(...), (array) $branchIds)));
        $branches = Branch::query()->whereIn('id', $ids)->get();
        if ($branches->count() !== count($ids)) {
            throw InvalidAdminData::invalid('One of the selected branches does not exist.');
        }

        $user->branches()->sync(
            $branches->mapWithKeys(fn (Branch $branch): array => [$branch->id => ['is_default' => $branch->id === $ids[0]]])->all(),
        );

        return array_values($branches->pluck('code')->map(strval(...))->all());
    }

    private function guardDeactivation(User $actor, User $user): void
    {
        if ($actor->is($user)) {
            throw InvalidAdminData::invalid('You cannot deactivate your own account.');
        }
        if ($user->hasRole(SystemRoles::OWNER) && ! $this->otherActiveOwnerExists($user)) {
            throw InvalidAdminData::invalid('The business needs at least one active Owner.');
        }
        if ($user->checkPermissionTo('users.manage') && ! $this->otherActiveUsersManageHolderExists($user)) {
            throw InvalidAdminData::invalid('The business needs at least one active user with users.manage.');
        }
    }

    private function otherActiveUsersManageHolderExists(User $user): bool
    {
        return User::query()->inCurrentTenant()->where('is_active', true)->whereKeyNot($user->id)
            ->whereHas('roles.permissions', fn ($q) => $q->where('name', 'users.manage'))->exists();
    }

    private function otherActiveOwnerExists(User $user): bool
    {
        return User::query()->inCurrentTenant()->where('is_active', true)->whereKeyNot($user->id)->role(SystemRoles::OWNER)->exists();
    }

    private function blank(mixed $value): ?string
    {
        $value = is_string($value) ? trim($value) : '';

        return $value === '' ? null : $value;
    }

    private function phone(mixed $value): ?string
    {
        $raw = $this->blank($value);
        if ($raw === null) {
            return null;
        }

        return BdPhone::toE164($raw) ?? throw InvalidAdminData::invalid('The phone number is not a valid Bangladeshi mobile number.');
    }
}
