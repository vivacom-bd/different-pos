<?php

declare(strict_types=1);

namespace App\Http\Controllers\Catalog;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Support\Catalog\ProductPresenter;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * "Reuse an uploaded image" in the product dialog: the distinct photos already on this tenant's
 * products (BelongsToTenant scopes the query), newest first, with one product name as the caption.
 * Picking one copies the path onto the product (`existing_image`); no file is copied.
 */
final class ProductImageController extends Controller
{
    use AuthorizesRequests;

    private const int LIMIT = 60;

    public function __invoke(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Product::class);
        $term = trim($request->string('q')->toString());

        $rows = Product::query()
            ->whereNotNull('image_path')
            ->where('image_path', '!=', '')
            ->when($term !== '', fn ($q) => $q->search($term))
            ->selectRaw('image_path, MIN(name) as name, COUNT(*) as uses, MAX(id) as latest')
            ->groupBy('image_path')
            ->orderByDesc('latest')
            ->limit(self::LIMIT)
            ->toBase()
            ->get();

        return response()->json([
            'images' => $rows->map(fn (object $row): array => [
                'path' => (string) $row->image_path,
                'url' => ProductPresenter::imageUrl((string) $row->image_path),
                'name' => (string) $row->name,
                'uses' => (int) $row->uses,
            ])->values(),
        ]);
    }
}
