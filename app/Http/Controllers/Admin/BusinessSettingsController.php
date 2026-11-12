<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Actions\Settings\SaveBusinessSettings;
use App\Actions\Settings\SaveTenantLogo;
use App\Enums\CustomerPinPolicy;
use App\Http\Controllers\Concerns\TranslatesDomainErrors;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SaveTenantLogoRequest;
use App\Models\Tenant;
use App\Models\Unit;
use App\Models\User;
use App\Support\Http\ScopedExists;
use App\Support\Tenancy\TenantContext;
use DateTimeZone;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** /app/settings/business and its logo (settings.manage via route middleware). */
final class BusinessSettingsController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [new Middleware('can:settings.manage')];
    }

    use TranslatesDomainErrors;

    public function edit(): Response
    {
        $tenant = app(TenantContext::class)->tenant();

        return Inertia::render('settings/business', [
            'business' => [
                'name' => $tenant->name,
                'slug' => $tenant->slug,
                'legal_name' => $tenant->legal_name,
                'vat_bin' => $tenant->vat_bin,
                'vat_registered_address' => $tenant->vat_registered_address,
                'phone' => $tenant->phone,
                'email' => $tenant->email,
                'address' => $tenant->address,
                'currency' => $tenant->currency,
                'timezone' => $tenant->timezone,
                'locale' => $tenant->locale,
                'logo_url' => $tenant->logoUrl(),
                'tax_inclusive' => (bool) $tenant->setting('tax_inclusive'),
                'backdate_days' => (int) $tenant->setting('backdate_days'),
                'return_days' => $tenant->setting('return_days'),
                'default_unit_id' => Unit::defaultId(),
                'default_credit_limit' => $tenant->setting('default_credit_limit'),
                'customer_pin_policy' => CustomerPinPolicy::fromSetting($tenant->setting('customer_pin_policy'))->value,
                'offline_credit_cap' => (int) $tenant->setting('offline_credit_cap'),
            ],
            'timezones' => DateTimeZone::listIdentifiers(),
            'units' => Unit::query()->where('is_active', true)->orderBy('name')->get(['id', 'name', 'short_name']),
        ]);
    }

    public function update(Request $request, SaveBusinessSettings $save): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'legal_name' => ['nullable', 'string', 'max:200'],
            'vat_bin' => ['nullable', 'string', 'max:20'],
            'vat_registered_address' => ['nullable', 'string', 'max:500'],
            'phone' => ['nullable', 'string', 'max:20'],
            'email' => ['nullable', 'email', 'max:150'],
            'address' => ['nullable', 'string', 'max:500'],
            'currency' => ['required', 'string', 'size:3'],
            'timezone' => ['required', Rule::in(DateTimeZone::listIdentifiers())],
            'locale' => ['required', Rule::in(['en', 'bn'])],
            'tax_inclusive' => ['required', 'boolean'],
            'backdate_days' => ['required', 'integer', 'min:0', 'max:365'],
            'default_unit_id' => ['nullable', 'integer', ScopedExists::in('units')],
            'return_days' => ['nullable', 'integer', 'min:0', 'max:3650'],
            'default_credit_limit' => ['nullable', 'integer', 'min:0'],
            'customer_pin_policy' => ['required', Rule::enum(CustomerPinPolicy::class)],
            'offline_credit_cap' => ['required', 'integer', 'min:0'],
        ]);

        $user = $request->user();
        abort_unless($user instanceof User, 401);
        $this->guarded(fn (): Tenant => $save->handle($user, $data));
        Inertia::flash('toast', ['type' => 'success', 'message' => __('app.saved')]);

        return back();
    }

    public function updateLogo(SaveTenantLogoRequest $request, SaveTenantLogo $save): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        $logo = $request->file('logo');
        abort_unless($logo instanceof UploadedFile, 422);
        $save->handle($user, $logo);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('app.logo_saved')]);

        return back();
    }

    public function destroyLogo(Request $request, SaveTenantLogo $save): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        $save->handle($user, null);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('app.logo_removed')]);

        return back();
    }
}
