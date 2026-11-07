<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Actions\Users\DeactivateUser;
use App\Actions\Users\ReactivateUser;
use App\Actions\Users\ResetStaffPin;
use App\Actions\Users\SaveUser;
use App\Http\Controllers\Admin\Concerns\RendersStaffPage;
use App\Http\Controllers\Concerns\TranslatesDomainErrors;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SaveUserRequest;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Inertia\Inertia;
use Inertia\Response;

/** /app/settings/users: the Users & Roles page (staff, Add/Edit dialog, Reset PIN, permission matrix); users.manage via route middleware. */
final class UserController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [new Middleware('can:users.manage')];
    }

    use RendersStaffPage;
    use TranslatesDomainErrors;

    public function index(Request $request): Response
    {
        return $this->staffPage($request);
    }

    /** The Add Staff dialog over the list (deep link). */
    public function create(Request $request): Response
    {
        return $this->staffPage($request, ['dialog' => 'create']);
    }

    /** The Edit Staff dialog over the list (deep link). */
    public function edit(Request $request, int $user): Response
    {
        $model = User::query()->inCurrentTenant()->findOrFail($user);

        return $this->staffPage($request, ['dialog' => 'edit', 'editing' => $this->staffForm($model)]);
    }

    public function store(SaveUserRequest $request, SaveUser $save): RedirectResponse
    {
        $this->guarded(fn (): User => $save->handle($this->actor($request), $this->data($request)));
        Inertia::flash('toast', ['type' => 'success', 'message' => __('app.created')]);

        return redirect()->route('users.index');
    }

    public function update(SaveUserRequest $request, int $user, SaveUser $save): RedirectResponse
    {
        $model = User::query()->inCurrentTenant()->findOrFail($user);
        $this->guarded(fn (): User => $save->handle($this->actor($request), $this->data($request), $model));
        Inertia::flash('toast', ['type' => 'success', 'message' => __('app.updated')]);

        return redirect()->route('users.index');
    }

    public function deactivate(Request $request, int $user, DeactivateUser $deactivate): RedirectResponse
    {
        $model = User::query()->inCurrentTenant()->findOrFail($user);
        $this->guarded(fn (): User => $deactivate->handle($this->actor($request), $model));
        Inertia::flash('toast', ['type' => 'success', 'message' => __('app.updated')]);

        return back();
    }

    /** POST /app/settings/users/{user}/reactivate: restores a deactivated user. */
    public function reactivate(Request $request, int $user, ReactivateUser $reactivate): RedirectResponse
    {
        $model = User::query()->inCurrentTenant()->findOrFail($user);
        $this->guarded(fn (): User => $reactivate->handle($this->actor($request), $model));
        Inertia::flash('toast', ['type' => 'success', 'message' => __('app.updated')]);

        return back();
    }

    /** POST /app/settings/users/{user}/pin: sets a new register PIN and clears a lockout. */
    public function resetPin(Request $request, int $user, ResetStaffPin $reset): RedirectResponse
    {
        $model = User::query()->inCurrentTenant()->findOrFail($user);
        $pin = (string) $request->validate(['pin' => ['required', 'string', 'regex:'.ResetStaffPin::PATTERN]])['pin'];
        $this->guarded(fn (): User => $reset->handle($this->actor($request), $model, $pin), 'pin');
        Inertia::flash('toast', ['type' => 'success', 'message' => __('app.pin_reset', ['name' => $model->name])]);

        return back();
    }

    /** @return array<string, mixed> */
    private function data(SaveUserRequest $request): array
    {
        $data = $request->validated();
        if (($data['password'] ?? null) === null) {
            unset($data['password']);
        }

        return $data;
    }

    private function actor(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        return $user;
    }
}
