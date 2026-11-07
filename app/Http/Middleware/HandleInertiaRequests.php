<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\User;
use App\Reports\Dashboard\TodayTicker;
use App\Support\Platform\Impersonation;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Inertia\Middleware;
use Spatie\Permission\PermissionRegistrar;

final class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * The branch id is part of the version on purpose: when the branch changes, the version
     * changes, so Inertia drops every cached page and history entry for the old branch and
     * forces a fresh server fetch. Without this, browser Back/Forward and prefetched links
     * restore old-branch page props (every list, report and the dashboard) until a hard
     * refresh, even though the shared auth.branch_id already shows the new branch.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        $context = app(TenantContext::class);
        $branch = $context->hasBranch() ? $context->branchId() : 0;

        return parent::version($request).':branch-'.$branch;
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $context = app(TenantContext::class);

        return [
            ...parent::share($request),
            'name' => config('app.name'),
            'auth' => [
                'user' => $request->user(),
                // Lazy: the tenant context is set by route middleware that runs after this one.
                'permissions' => fn (): array => $this->permissions($request, $context),
                'roles' => fn (): array => $this->roles($request, $context),
                'branches' => fn (): array => $this->branches($request, $context),
                'branch_id' => fn (): ?int => $context->hasBranch() ? $context->branchId() : null,
            ],
            // Lazy for the same reason; null outside a tenant context (guests, the platform console).
            'tenant' => fn (): ?array => $context->hasTenant()
                ? ['name' => $context->tenant()->name, 'logo_url' => $context->tenant()->logoUrl()]
                : null,
            // The top bar's today ticker (current branch, 60 s cache); null without dashboard.view.
            // Named todayTicker so it never collides with a page's own `today` date prop.
            'todayTicker' => fn (): ?array => $this->today($request, $context),
            'preferences' => fn (): array => $this->preferences($request),
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
            // Platform admin signed in as a tenant Owner: the shell shows a banner with Exit (null otherwise).
            'impersonation' => fn (): ?array => $this->impersonation($request, $context),
        ];
    }

    /** @return list<string> */
    private function permissions(Request $request, TenantContext $context): array
    {
        $user = $request->user();
        // Platform admins hold no tenant permissions; `platform.admin` is the gate name the console nav checks.
        if ($user instanceof User && $user->is_platform_admin && $user->tenant_id === null) {
            return ['platform.admin'];
        }
        if (! $user instanceof User || ! $context->hasTenant() || $user->tenant_id !== $context->tenantId()) {
            return [];
        }

        return array_values($user->getAllPermissions()->map(static fn ($permission): string => (string) $permission->name)->values()->all());
    }

    /** @return array{user: string, tenant: string|null, expires_at: string|null}|null */
    private function impersonation(Request $request, TenantContext $context): ?array
    {
        $user = $request->user();
        $impersonation = app(Impersonation::class);
        if (! $user instanceof User || ! $request->hasSession() || ! $impersonation->isActive()) {
            return null;
        }

        return [
            'user' => $user->name,
            'tenant' => $context->hasTenant() ? $context->tenant()->name : $user->tenant?->name,
            'expires_at' => $impersonation->expiresAt()?->toIso8601String(),
        ];
    }

    /** @return array{total_paisa: int, sales: int}|null */
    private function today(Request $request, TenantContext $context): ?array
    {
        $user = $request->user();
        if (! $user instanceof User || ! $context->hasTenant() || ! $context->hasBranch()
            || $user->tenant_id !== $context->tenantId() || ! $user->can('dashboard.view')) {
            return null;
        }

        return app(TodayTicker::class)->get();
    }

    /** @return list<string> */
    private function roles(Request $request, TenantContext $context): array
    {
        $user = $request->user();
        if (! $user instanceof User || ! $context->hasTenant() || $user->tenant_id !== $context->tenantId()) {
            return [];
        }
        app(PermissionRegistrar::class)->setPermissionsTeamId($context->tenantId());

        return array_values($user->roles()->orderBy('roles.id')->pluck('roles.name')->map(static fn ($n): string => (string) $n)->all());
    }

    /** @return list<array{id: int, name: string}> */
    private function branches(Request $request, TenantContext $context): array
    {
        $user = $request->user();
        if (! $user instanceof User || ! $context->hasTenant() || $user->tenant_id !== $context->tenantId()) {
            return [];
        }

        return array_values($user->branches()->where('branches.is_active', true)->orderBy('branches.name')->get()
            ->map(static fn ($branch): array => ['id' => (int) $branch->id, 'name' => (string) $branch->name])
            ->all());
    }

    /** @return array{locale: string, theme: string, scheme: string, density: string, bn_digits: bool} */
    private function preferences(Request $request): array
    {
        $user = $request->user();
        $p = $user instanceof User ? ($user->preferences ?? []) : [];

        return [
            'locale' => app()->getLocale() === 'bn' ? 'bn' : 'en',
            'theme' => is_string($p['theme'] ?? null) ? $p['theme'] : 'system',
            'scheme' => is_string($p['scheme'] ?? null) ? $p['scheme'] : 'teal',
            'density' => ($p['density'] ?? null) === 'compact' ? 'compact' : 'comfortable',
            'bn_digits' => (bool) ($p['bn_digits'] ?? true),
        ];
    }
}
