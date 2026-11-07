<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Concerns;

use App\Models\Branch;
use App\Models\User;
use App\Support\Http\TableQuery;
use App\Support\Permissions\Permission;
use App\Support\Permissions\SystemRoles;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Permission\Models\Role;

/**
 * The one "Users & Roles" page (`settings/users/index`, baseline mockup screen 20): the staff list, the
 * Add/Edit staff dialog and the permission matrix. /app/settings/users, /users/create, /users/{user}/edit
 * and /app/settings/roles all render it with a different dialog or focus.
 */
trait RendersStaffPage
{
    /** @param  array<string, mixed>  $extra */
    protected function staffPage(Request $request, array $extra = []): Response
    {
        $table = TableQuery::fromRequest($request, ['name', 'email', 'last_login_at'], ['status', 'role'], 'name');

        $query = User::query()->inCurrentTenant()->with(['roles:id,name', 'branches:branches.id,branches.code,branches.name'])
            ->when($table->search !== '', function (Builder $q) use ($table): void {
                $like = '%'.addcslashes($table->search, '\\%_').'%';
                $q->where(fn (Builder $w) => $w->where('name', 'like', $like)->orWhere('email', 'like', $like)
                    ->orWhere('username', 'like', $like)->orWhere('phone', 'like', $like));
            })
            ->when(($table->filters['status'] ?? '') === 'active', fn (Builder $q) => $q->where('is_active', true))
            ->when(($table->filters['status'] ?? '') === 'inactive', fn (Builder $q) => $q->where('is_active', false))
            ->when(isset($table->filters['role']), fn (Builder $q) => $q->whereHas('roles', fn (Builder $r) => $r->where('roles.id', (int) $table->filters['role'])));

        $page = $table->applySort($query)->paginate($table->perPage, ['*'], 'page', $table->page);
        $tenantId = app(TenantContext::class)->tenantId();
        $roles = Role::query()->where('tenant_id', $tenantId)->with('permissions:id,name')->withCount('users')->orderBy('id')->get();

        return Inertia::render('settings/users/index', [
            'users' => $this->staffRows($page->items()),
            'meta' => ['page' => $page->currentPage(), 'perPage' => $page->perPage(), 'total' => $page->total(), 'lastPage' => $page->lastPage()],
            'roles' => array_values($roles->sortBy('name')->map(static fn (Role $r): array => [
                'id' => (int) $r->id, 'name' => $r->name, 'is_system' => (bool) $r->getAttribute('is_system'),
                'permissions' => array_values($r->permissions->pluck('name')->map(strval(...))->all()),
            ])->all()),
            'branches' => array_values(Branch::query()->where('is_active', true)->orderBy('name')->get()
                ->map(static fn (Branch $b): array => ['id' => $b->id, 'name' => $b->name, 'code' => $b->code])->all()),
            'matrix' => [
                'permissions' => Permission::ALL,
                'sensitive_permissions' => Permission::SENSITIVE,
                'is_owner' => $request->user()?->hasRole(SystemRoles::OWNER) ?? false,
                'roles' => array_values($roles->map(static fn (Role $r): array => [
                    'id' => (int) $r->id,
                    'name' => $r->name,
                    'is_system' => (bool) $r->getAttribute('is_system'),
                    'max_discount_pct_bp' => (int) $r->getAttribute('max_discount_pct_bp'),
                    'permissions' => array_values($r->permissions->pluck('name')->map(strval(...))->all()),
                    'users' => (int) $r->getAttribute('users_count'),
                ])->all()),
            ],
            'business' => app(TenantContext::class)->tenant()->name,
            'dialog' => null,
            'editing' => null,
            'focus' => null,
            ...$extra,
        ]);
    }

    /** @return array<string, mixed> */
    protected function staffForm(User $model): array
    {
        $model->loadMissing(['roles:id', 'branches:branches.id']);

        return [
            'id' => $model->id,
            'name' => $model->name,
            'email' => $model->email,
            'username' => $model->username,
            'phone' => $model->phone,
            'is_active' => $model->is_active,
            'must_change_password' => $model->must_change_password,
            'role_ids' => array_values($model->roles->pluck('id')->map(intval(...))->all()),
            'branch_ids' => array_values($model->branches->pluck('id')->map(intval(...))->all()),
        ];
    }

    /**
     * @param  array<array-key, mixed>  $items
     * @return list<array<string, mixed>>
     */
    private function staffRows(array $items): array
    {
        $rows = [];
        foreach ($items as $u) {
            if (! $u instanceof User) {
                continue;
            }
            $rows[] = [
                'id' => $u->id,
                'name' => $u->name,
                'email' => $u->email,
                'username' => $u->username,
                'phone' => $u->phone,
                'is_active' => $u->is_active,
                'has_pin' => is_string($u->pin_hash) && $u->pin_hash !== '',
                'pin_locked' => $u->pin_locked_until !== null && $u->pin_locked_until->isFuture(),
                'last_login_at' => $u->last_login_at?->toIso8601String(),
                'roles' => array_values($u->roles->pluck('name')->map(strval(...))->all()),
                'branches' => array_values($u->branches->map(static fn (Branch $b): string => $b->name)->all()),
            ];
        }

        return $rows;
    }
}
