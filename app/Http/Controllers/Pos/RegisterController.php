<?php

declare(strict_types=1);

namespace App\Http\Controllers\Pos;

use App\Enums\CustomerPinPolicy;
use App\Enums\PaymentMethodType;
use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Courier;
use App\Models\Customer;
use App\Models\PaymentMethod;
use App\Models\Register;
use App\Models\Sale;
use App\Models\User;
use App\Support\Pos\RegisterCustomer;
use App\Support\Sales\SaleGate;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use Inertia\Response;

/** GET /app/pos: the online register page with what it needs to price and settle a cart locally. */
final class RegisterController extends Controller
{
    public function __invoke(Request $request, TenantContext $context, SaleGate $gate, RegisterCustomer $customers): Response
    {
        Gate::authorize('create', Sale::class);
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        $tenant = $context->tenant();
        $branch = $context->branch();

        $customer = null;
        $uuid = $request->query('customer');
        if (is_string($uuid) && $uuid !== '') {
            $found = Customer::query()->where('uuid', $uuid)->where('is_walk_in', false)->first();
            $customer = $found instanceof Customer ? $customers->card($found) : null;
        }

        return Inertia::render('pos/register', [
            'branch' => ['id' => $branch->id, 'code' => $branch->code, 'name' => $branch->name],
            'registers' => Register::query()->where('is_active', true)->orderBy('name')->get(['id', 'name', 'code'])
                ->map(static fn (Register $r): array => ['id' => $r->id, 'name' => $r->name, 'code' => $r->code])->values()->all(),
            'paymentMethods' => PaymentMethod::query()->where('is_active', true)
                ->where('type', '!=', PaymentMethodType::ExchangeCredit)
                ->orderBy('sort_order')->orderBy('id')->get()
                ->map(static fn (PaymentMethod $m): array => [
                    'id' => $m->id,
                    'name' => $m->name,
                    'type' => $m->type->value,
                    'requires_reference' => $m->requires_reference,
                ])->values()->all(),
            'couriers' => Courier::query()->where('is_active', true)->orderByDesc('is_default')->orderBy('name')->pluck('name')->values()->all(),
            'categories' => Category::query()->where('is_active', true)->whereNull('parent_id')->orderBy('sort_order')->orderBy('name')
                ->limit(30)->get(['id', 'name', 'name_bn'])
                ->map(static fn (Category $c): array => ['id' => $c->id, 'name' => $c->name, 'name_bn' => $c->name_bn])->values()->all(),
            'settings' => [
                'tax_inclusive' => (bool) $tenant->setting('tax_inclusive'),
                'rounding_step' => (int) $branch->setting('cash_rounding'),
                'backdate_days' => (int) $tenant->setting('backdate_days'),
                'require_customer' => (bool) $tenant->setting('require_customer_on_sale'),
                'auto_print' => (bool) $branch->setting('auto_print'),
                'paper' => (string) $branch->setting('paper'),
                'allow_negative_stock' => (bool) $branch->setting('allow_negative_stock'),
                'pin_policy' => CustomerPinPolicy::fromSetting($tenant->setting('customer_pin_policy'))->value,
            ],
            'discountCapBp' => $gate->discountCapBp($user, null),
            'customer' => $customer,
            'receiptAvailable' => Route::has('sales.receipt'),
        ]);
    }
}
