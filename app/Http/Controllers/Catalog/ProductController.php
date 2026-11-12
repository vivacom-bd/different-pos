<?php

declare(strict_types=1);

namespace App\Http\Controllers\Catalog;

use App\Actions\Catalog\DeleteProduct;
use App\Actions\Catalog\GenerateFamilyVariants;
use App\Actions\Catalog\SaveProduct;
use App\Actions\Catalog\SaveProductFamily;
use App\Enums\DeleteOutcome;
use App\Enums\ProductItemType;
use App\Http\Controllers\Concerns\TranslatesDomainErrors;
use App\Http\Controllers\Controller;
use App\Http\Requests\Catalog\SaveProductRequest;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductFamily;
use App\Models\TaxRate;
use App\Models\Unit;
use App\Models\User;
use App\Models\VariantOption;
use App\Models\Vendor;
use App\Reports\ColumnType;
use App\Reports\ReportColumn;
use App\Reports\ReportResult;
use App\Support\Catalog\ProductPresenter;
use App\Support\Database\Transaction;
use App\Support\Export\ExportFormat;
use App\Support\Export\ExportMeta;
use App\Support\Export\TabularExport;
use App\Support\Http\TableQuery;
use App\Support\Money\Money;
use App\Support\Quantity\Quantity;
use App\Support\Stock\BranchStockColumns;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class ProductController extends Controller
{
    use AuthorizesRequests;
    use TranslatesDomainErrors;

    public function __construct(private readonly TenantContext $context, private readonly BranchStockColumns $stockColumns) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Product::class);

        return Inertia::render('products/index', [...$this->listing($request), 'editor' => null, 'form' => null]);
    }

    /** The add dialog over the list (02_AppFlow §2: a deep link renders the list with the dialog open). */
    public function create(Request $request): Response
    {
        $this->authorize('viewAny', Product::class);
        $this->authorize('create', Product::class);

        return Inertia::render('products/index', [
            ...$this->listing($request),
            'editor' => ['product' => null, 'families' => []],
            'form' => $this->formOptions(),
        ]);
    }

    /** The edit dialog over the list. */
    public function edit(Request $request, Product $product): Response
    {
        $this->authorize('update', $product);
        $canCost = $this->actor($request)->can('products.view_cost');
        $product->load('family:id,uuid,code,name');

        return Inertia::render('products/index', [
            ...$this->listing($request),
            'editor' => [
                'product' => ProductPresenter::detail($product, $canCost),
                'families' => $product->family_id === null ? $this->families() : [],
            ],
            'form' => $this->formOptions($product->unit_id),
        ]);
    }

    /**
     * The catalog list: filtered, sorted page of products with per-branch stock and totals over the whole filtered set.
     *
     * @return array<string, mixed>
     */
    private function listing(Request $request): array
    {
        $user = $this->actor($request);
        $canCost = $user->can('products.view_cost');

        $table = $this->table($request, $canCost);

        $filtered = $this->filtered($table);
        $page = $table->paginate((clone $filtered)
            ->selectRaw('products.*, COALESCE(branch_stocks.qty_on_hand, 0) AS on_hand')
            ->with(['category:id,name', 'brand:id,name', 'family:id,uuid', 'unit:id,short_name']));

        $branches = $this->stockColumns->branchesFor($user);
        /** @var list<Product> $items */
        $items = array_values($page->items());
        $stock = $this->stockColumns->onHand(array_map(static fn (Product $p): int => $p->id, $items), $branches);

        $sums = (clone $filtered)->toBase()
            ->selectRaw('COALESCE(SUM(products.sale_price), 0) AS sale, COALESCE(SUM(products.cost_price), 0) AS cost')
            ->first();
        /** @var object{sale: int|string, cost: int|string}|null $sums */
        $branchTotals = $this->stockColumns->totals($filtered, $branches);

        return [
            'rows' => array_map(static function (Product $p) use ($canCost, $stock): array {
                $row = ProductPresenter::row($p, $canCost);
                $perBranch = $p->track_stock ? ($stock[$p->id] ?? []) : null;
                $row['stock'] = $perBranch;
                $row['total_stock'] = $perBranch === null ? null : array_sum($perBranch);
                $row['track_stock'] = $p->track_stock;

                return $row;
            }, $items),
            'meta' => ['page' => $page->currentPage(), 'perPage' => $page->perPage(), 'total' => $page->total(), 'lastPage' => $page->lastPage()],
            'totals' => [
                'sale_price' => (int) ($sums->sale ?? 0),
                'cost_price' => $canCost ? (int) ($sums->cost ?? 0) : null,
                'stock' => $branchTotals,
                'total_stock' => array_sum($branchTotals),
            ],
            'branches' => $this->stockColumns->present($branches),
            'categories' => Category::query()->orderBy('name')->get(['id', 'name']),
            'brands' => Brand::query()->orderBy('name')->get(['id', 'name']),
            'canViewCost' => $canCost,
        ];
    }

    private function table(Request $request, bool $canCost): TableQuery
    {
        return TableQuery::fromRequest(
            $request,
            sortable: ['name', 'sku', 'barcode', 'sale_price', 'on_hand', ...($canCost ? ['cost_price'] : [])],
            filterable: ['category', 'brand', 'status', 'stock', 'added', 'type'],
            defaultSort: 'name',
        );
    }

    /** GET /app/products/export.{csv|xlsx|pdf}: the filtered list (same query string as the page). */
    public function export(Request $request, string $format, TabularExport $export): HttpResponse|StreamedResponse
    {
        $this->authorize('viewAny', Product::class);
        $user = $this->actor($request);
        $canCost = $user->can('products.view_cost');
        $table = $this->table($request, $canCost);
        $tz = $this->context->tenant()->timezone;
        $branches = $this->stockColumns->branchesFor($user);

        $filtered = $this->filtered($table);
        $query = (clone $filtered)
            ->selectRaw('products.*, COALESCE(branch_stocks.qty_on_hand, 0) AS on_hand')
            ->with(['category:id,name'])
            ->orderBy('products.name')->orderBy('products.id');

        $columns = [
            new ReportColumn('sku', 'SKU', ColumnType::Text),
            new ReportColumn('barcode', 'Barcode', ColumnType::Text),
            new ReportColumn('name', 'Product name', ColumnType::Text),
            new ReportColumn('name_bn', 'Name BN', ColumnType::Text),
            new ReportColumn('category', 'Category', ColumnType::Text),
        ];
        if ($canCost) {
            $columns[] = new ReportColumn('cost_price', 'Cost price', ColumnType::Money);
        }
        $columns[] = new ReportColumn('sale_price', 'Retail price', ColumnType::Money);
        foreach ($branches as $i => $branch) {
            $columns[] = new ReportColumn('stock_'.$i, 'Stock ('.$branch->name.')', ColumnType::Qty);
        }

        $rows = [];
        $sale = 0;
        $cost = 0;
        foreach ($query->lazy(500)->chunk(500) as $chunk) {
            /** @var list<Product> $products */
            $products = $chunk->values()->all();
            $stock = $this->stockColumns->onHand(array_map(static fn (Product $p): int => $p->id, $products), $branches);
            foreach ($products as $p) {
                $sale += $p->sale_price->paisa;
                $cost += $p->cost_price->paisa;
                $row = [
                    'sku' => (string) $p->sku, 'barcode' => (string) $p->barcode, 'name' => $p->name, 'name_bn' => (string) $p->name_bn,
                    'category' => (string) $p->category?->name, 'sale_price' => $p->sale_price->paisa,
                ];
                if ($canCost) {
                    $row['cost_price'] = $p->cost_price->paisa;
                }
                foreach ($branches as $i => $_) {
                    $row['stock_'.$i] = $p->track_stock ? ($stock[$p->id][$i] ?? 0) : null;
                }
                $rows[] = $row;
            }
        }

        $totals = ['sale_price' => $sale];
        if ($canCost) {
            $totals['cost_price'] = $cost;
        }
        foreach ($this->stockColumns->totals($filtered, $branches) as $i => $milli) {
            $totals['stock_'.$i] = $milli;
        }

        return $export->respond(
            new ReportResult($columns, $rows, $totals),
            ExportFormat::from($format),
            'products-'.now($tz)->format('Ymd-His'),
            ExportMeta::for($this->context, 'Products'),
        );
    }

    /**
     * The list query with search and filters applied, joined to the current branch's stock row (no select, no order).
     *
     * @return Builder<Product>
     */
    private function filtered(TableQuery $table): Builder
    {
        $branchId = $this->context->branchId();
        $query = Product::query()
            ->leftJoin('branch_stocks', static function ($join) use ($branchId): void {
                $join->on('branch_stocks.product_id', '=', 'products.id')->where('branch_stocks.branch_id', '=', $branchId);
            });

        if ($table->search !== '') {
            $query->search($table->search);
        }
        if (isset($table->filters['category'])) {
            $query->where('products.category_id', (int) $table->filters['category']);
        }
        if (isset($table->filters['brand'])) {
            $query->where('products.brand_id', (int) $table->filters['brand']);
        }
        if (($itemType = ProductItemType::tryFrom((string) ($table->filters['type'] ?? ''))) instanceof ProductItemType) {
            $query->where('products.item_type', $itemType->value);
        }
        $onHand = 'COALESCE(branch_stocks.qty_on_hand, 0)';
        $low = static fn (Builder $q): Builder => $q->where('products.track_stock', true)
            ->where('products.reorder_level', '>', 0)
            ->whereRaw("{$onHand} <= products.reorder_level");
        match ($table->filters['status'] ?? null) {
            'active' => $query->where('products.is_active', true),
            'inactive' => $query->where('products.is_active', false),
            'low' => $low($query),
            default => null,
        };
        match ($table->filters['stock'] ?? null) {
            'out' => $query->where('products.track_stock', true)->whereRaw("{$onHand} <= 0"),
            'low' => $low($query)->whereRaw("{$onHand} > 0"),
            'in' => $query->where('products.track_stock', true)->whereRaw("{$onHand} > 0")
                ->where(static fn (Builder $q): Builder => $q->where('products.reorder_level', '<=', 0)->orWhereRaw("{$onHand} > products.reorder_level")),
            default => null,
        };
        $timezone = $this->context->tenant()->timezone;
        $since = match ($table->filters['added'] ?? null) {
            'today' => CarbonImmutable::now($timezone)->startOfDay(),
            '7d' => CarbonImmutable::now($timezone)->subDays(7)->startOfDay(),
            '30d' => CarbonImmutable::now($timezone)->subDays(30)->startOfDay(),
            '90d' => CarbonImmutable::now($timezone)->subDays(90)->startOfDay(),
            default => null,
        };
        if ($since instanceof CarbonImmutable) {
            $query->where('products.created_at', '>=', $since->utc());
        }

        return $query;
    }

    public function store(SaveProductRequest $request, SaveProduct $save, SaveProductFamily $saveFamily, GenerateFamilyVariants $generate): RedirectResponse
    {
        $user = $this->actor($request);
        $data = $this->productData($request);
        $variants = $request->input('variants');

        if (is_array($variants) && $variants !== []) {
            $family = $this->guarded(fn (): mixed => Transaction::run(function () use ($user, $data, $variants, $saveFamily, $generate): ProductFamily {
                $family = $saveFamily->handle($user, [
                    'code' => $data['sku'] ?? '',
                    'name' => $data['name'] ?? '',
                    'name_bn' => $data['name_bn'] ?? null,
                    'category_id' => $data['category_id'] ?? null,
                    'brand_id' => $data['brand_id'] ?? null,
                    'unit_id' => $data['unit_id'] ?? 0,
                    'tax_rate_id' => $data['tax_rate_id'] ?? null,
                    'option1_id' => (int) $variants['option1_id'],
                    'option2_id' => isset($variants['option2_id']) ? (int) $variants['option2_id'] : null,
                    'description' => $data['description'] ?? null,
                    'image_path' => $data['image_path'] ?? null,
                ]);
                $generate->handle($user, $family, $this->variantRows($variants), [
                    'sale_price' => $data['sale_price'] ?? Money::zero(),
                    'cost_price' => $data['cost_price'] ?? Money::zero(),
                ]);

                return $family;
            }));

            return to_route('families.show', $family)->with('toast', ['type' => 'success', 'message' => __('Variants generated.')]);
        }

        $this->guarded(fn (): Product => $save->handle($user, $data));
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Product saved.')]);

        return $this->toList($request);
    }

    public function update(SaveProductRequest $request, Product $product, SaveProduct $save): RedirectResponse
    {
        $user = $this->actor($request);
        $data = $this->productData($request, $product);
        $old = $product->image_path;

        $this->guarded(fn (): Product => $save->handle($user, $data, $product));
        if ($old !== null && array_key_exists('image_path', $data) && $old !== $data['image_path'] && ! $this->imageInUse($old)) {
            Storage::disk('public')->delete($old);
        }
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Product saved.')]);

        return $this->toList($request);
    }

    public function active(Request $request, Product $product, SaveProduct $save): RedirectResponse
    {
        $this->authorize('update', $product);
        $save->handle($this->actor($request), ['is_active' => $request->boolean('is_active')], $product);

        return back();
    }

    public function destroy(Request $request, Product $product, DeleteProduct $delete): RedirectResponse
    {
        $this->authorize('delete', $product);
        $outcome = $this->guarded(fn (): DeleteOutcome => $delete->handle($this->actor($request), $product));
        Inertia::flash('toast', ['type' => 'success', 'message' => $outcome === DeleteOutcome::Deleted ? __('Product deleted.') : __('Product is in use, so it was deactivated.')]);

        return $this->toList($request);
    }

    /** A photo can be shared (reused from the picker, or copied from a family to its variants): keep the file while anything still points at it. */
    private function imageInUse(string $path): bool
    {
        return Product::withTrashed()->where('image_path', $path)->exists()
            || ProductFamily::withTrashed()->where('image_path', $path)->exists();
    }

    /**
     * @return array<string, mixed>
     */
    private function productData(SaveProductRequest $request, ?Product $product = null): array
    {
        /** @var array<string, mixed> $v */
        $v = $request->validated();

        $data = [
            'name' => $v['name'],
            'name_bn' => $v['name_bn'] ?? null,
            'sku' => $v['sku'],
            'barcode' => $v['barcode'] ?? null,
            'hs_code' => $v['hs_code'] ?? null,
            'type' => $v['type'],
            'item_type' => $v['item_type'],
            'category_id' => $v['category_id'] ?? null,
            'brand_id' => $v['brand_id'] ?? null,
            'unit_id' => (int) $v['unit_id'],
            'tax_rate_id' => $v['tax_rate_id'] ?? null,
            'default_vendor_id' => $v['default_vendor_id'] ?? null,
            'sale_price' => Money::ofPaisa((int) ($v['sale_price'] ?? 0)),
            'min_sale_price' => isset($v['min_sale_price']) ? Money::ofPaisa((int) $v['min_sale_price']) : null,
            'wholesale_price' => isset($v['wholesale_price']) ? Money::ofPaisa((int) $v['wholesale_price']) : null,
            'min_sale_qty' => Quantity::ofMilli((int) ($v['min_sale_qty'] ?? 0)),
            'reorder_level' => Quantity::ofMilli((int) ($v['reorder_level'] ?? 0)),
            'track_stock' => (bool) $v['track_stock'],
            'is_active' => (bool) $v['is_active'],
            'description' => $v['description'] ?? null,
        ];
        // Cost is write-protected for users without products.view_cost: the field is simply ignored.
        if ($this->actor($request)->can('products.view_cost') && isset($v['cost_price'])) {
            $data['cost_price'] = Money::ofPaisa((int) $v['cost_price']);
        }

        // Opening stock (create only; the request already rejects it without stock.adjust or with variants).
        if (! $product instanceof Product && ($v['opening_qty'] ?? null) !== null) {
            $data['opening_qty'] = Quantity::ofMilli((int) $v['opening_qty']);
        }

        $image = $request->file('image');
        if ($image instanceof UploadedFile) {
            $data['image_path'] = $image->store('products', 'public');
        } elseif (is_string($v['existing_image'] ?? null) && $v['existing_image'] !== '') {
            $data['image_path'] = $v['existing_image'];
        } elseif ($product instanceof Product && (bool) ($v['remove_image'] ?? false)) {
            $data['image_path'] = null;
        }

        return $data;
    }

    /**
     * @param  array<string, mixed>  $variants
     * @return list<array{option1_value_id: int, option2_value_id?: int|null, sale_price?: Money, cost_price?: Money, sku?: string, barcode?: string}>
     */
    private function variantRows(array $variants): array
    {
        $rows = [];
        /** @var list<array<string, mixed>> $input */
        $input = $variants['rows'];
        foreach ($input as $row) {
            $out = ['option1_value_id' => (int) $row['option1_value_id']];
            if (isset($row['option2_value_id'])) {
                $out['option2_value_id'] = (int) $row['option2_value_id'];
            }
            if (isset($row['sale_price'])) {
                $out['sale_price'] = Money::ofPaisa((int) $row['sale_price']);
            }
            if (isset($row['cost_price'])) {
                $out['cost_price'] = Money::ofPaisa((int) $row['cost_price']);
            }
            foreach (['sku', 'barcode'] as $key) {
                if (isset($row[$key]) && is_string($row[$key])) {
                    $out[$key] = $row[$key];
                }
            }
            $rows[] = $out;
        }

        return $rows;
    }

    /** @return list<array<string, mixed>> */
    private function families(): array
    {
        $option = static fn (?VariantOption $o): ?array => $o instanceof VariantOption ? [
            'id' => $o->id,
            'name' => $o->name,
            'values' => $o->values->map(static fn ($v): array => ['id' => $v->id, 'value' => $v->value, 'code' => $v->code])->all(),
        ] : null;

        return array_values(ProductFamily::query()->where('is_active', true)->with(['option1.values', 'option2.values'])->orderBy('name')->limit(300)->get()
            ->map(static fn (ProductFamily $f): array => ['id' => $f->id, 'uuid' => $f->uuid, 'code' => $f->code, 'name' => $f->name, 'option1' => $option($f->option1), 'option2' => $option($f->option2)])
            ->all());
    }

    /** @return array<string, mixed> */
    private function formOptions(?int $keepUnitId = null): array
    {
        return [
            'categories' => Category::query()->orderBy('name')->get(['id', 'name', 'parent_id']),
            'brands' => Brand::query()->orderBy('name')->get(['id', 'name']),
            // Only active units are offered; an edited product keeps its own unit even when it was deactivated since.
            'units' => Unit::query()->where(static fn ($q) => $q->where('is_active', true)->when($keepUnitId !== null, static fn ($q) => $q->orWhere('id', $keepUnitId)))->orderBy('name')->get(['id', 'name', 'short_name', 'allows_decimal']),
            'defaultUnitId' => Unit::defaultId(),
            'canOpenStock' => $this->actor(request())->can('stock.adjust'),
            'taxRates' => TaxRate::query()->orderBy('name')->get(['id', 'name', 'rate_bp']),
            'vendors' => Vendor::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'variantOptions' => VariantOption::query()->with('values:id,variant_option_id,value,code')->orderBy('sort_order')->get(['id', 'name']),
        ];
    }

    /** Back to the list the dialog was opened over: the URL's list query (search, sort, page, filters) is kept. */
    private function toList(Request $request): RedirectResponse
    {
        return to_route('products.index', Arr::only($request->query(), ['q', 'sort', 'page', 'per_page', 'filter']));
    }

    private function actor(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        return $user;
    }
}
