<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Sale;
use App\Models\User;
use App\Support\Customers\CustomerSearch;
use App\Support\Customers\CustomerSearchResult;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /app/api/search?q=: command-palette lookups. Each group is authorized by its own policy and
 * the models are tenant (and, for sales, branch) scoped, so another tenant's rows never appear.
 * Customer PIN data and product cost never leave here.
 */
final class SearchController extends Controller
{
    private const int LIMIT = 8;

    public function __construct(private readonly CustomerSearch $customerSearch) {}

    public function __invoke(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        $q = trim((string) $request->query('q', ''));
        if (mb_strlen($q) < 2) {
            return response()->json(['products' => [], 'customers' => [], 'sales' => []]);
        }
        $q = mb_substr($q, 0, 60);
        $prefix = addcslashes($q, '\\%_').'%';

        return response()->json([
            'products' => $user->can('viewAny', Product::class) ? $this->products($q) : [],
            'customers' => $user->can('viewAny', Customer::class) ? $this->customers($q) : [],
            'sales' => $user->can('viewAny', Sale::class) ? $this->sales($prefix) : [],
        ]);
    }

    /** @return list<array<string, mixed>> */
    private function products(string $q): array
    {
        return array_values(Product::query()
            ->where('is_active', true)
            ->search($q)->orderByCodeMatch($q)
            ->orderBy('name')
            ->limit(self::LIMIT)
            ->get()
            ->map(fn (Product $p): array => [
                'id' => $p->id,
                'uuid' => $p->uuid,
                'label' => $p->name,
                'sub' => $p->sku,
                'price' => $p->sale_price->paisa,
            ])
            ->all());
    }

    /** @return list<array<string, mixed>> */
    private function customers(string $q): array
    {
        // "#10000017" or "1000 0017": the customer number; the finder also matches phone suffixes and names.
        return array_values($this->customerSearch->search(ltrim($q, '#'), self::LIMIT)
            ->filter(static fn (CustomerSearchResult $c): bool => $c->isActive)
            ->map(static fn (CustomerSearchResult $c): array => [
                'id' => $c->id,
                'uuid' => $c->uuid,
                'label' => $c->name,
                'sub' => trim(($c->customerNo !== null ? '#'.$c->customerNo : '').' '.($c->phone ?? '')),
            ])
            ->all());
    }

    /** @return list<array<string, mixed>> */
    private function sales(string $prefix): array
    {
        return array_values(Sale::query()
            ->whereNotNull('number')
            ->where('number', 'like', $prefix)
            ->latest('sold_at')
            ->limit(self::LIMIT)
            ->get()
            ->map(fn (Sale $s): array => [
                'id' => $s->id,
                'uuid' => $s->uuid,
                'label' => (string) $s->number,
                'sub' => $s->status->value,
                'total' => $s->total->paisa,
            ])
            ->all());
    }
}
