<?php

declare(strict_types=1);

namespace App\Http\Controllers\Sales;

use App\Enums\SaleStatus;
use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Sale;
use App\Models\Tenant;
use App\Support\Receipts\ReceiptBuilder;
use App\Support\Receipts\ReceiptRenderer;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * GET /r/{token}: the shared receipt, no login. The 32-character random token is the only
 * credential; a sale without a token (or a revoked one) is a 404. Shows no customer phone, number
 * or BIN, and the view model never carries cost or PIN data. Rate limited by the route.
 */
final class PublicReceiptController extends Controller
{
    public function __invoke(Request $request, string $token, TenantContext $context, ReceiptBuilder $builder, ReceiptRenderer $renderer): Response
    {
        abort_unless(preg_match('/^[A-Za-z0-9]{32}$/', $token) === 1, 404);

        /** @var array{0: Sale, 1: Tenant, 2: Branch}|null $found */
        $found = $context->withoutTenancy(static function () use ($token): ?array {
            $sale = Sale::query()->where('public_token', $token)->where('status', '!=', SaleStatus::Held)->first();
            if (! $sale instanceof Sale) {
                return null;
            }
            $tenant = Tenant::query()->find($sale->tenant_id);
            $branch = Branch::query()->find($sale->branch_id);

            return $tenant instanceof Tenant && $branch instanceof Branch && $tenant->isActive() ? [$sale, $tenant, $branch] : null;
        });
        abort_if($found === null, 404);
        [$sale, $tenant, $branch] = $found;

        $lang = $request->query('lang');
        $locale = is_string($lang) && in_array($lang, ['en', 'bn'], true) ? $lang : ($tenant->locale === 'bn' ? 'bn' : 'en');
        app()->setLocale($locale);
        $paper = in_array($request->query('paper'), ReceiptRenderer::PAPERS, true) ? (string) $request->query('paper') : 'a4';

        $html = $context->run($tenant, $branch, static fn (): string => $renderer->html(
            $paper === 'a4' ? 'a4' : 'thermal',
            $builder->sale($sale, $locale, public: true),
            $paper,
        ));

        return response($html, 200, [
            'Content-Type' => 'text/html; charset=utf-8',
            'Cache-Control' => 'private, max-age=300',
            'X-Robots-Tag' => 'noindex, nofollow',
            'Referrer-Policy' => 'no-referrer',
        ]);
    }
}
