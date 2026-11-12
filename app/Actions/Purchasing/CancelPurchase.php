<?php

declare(strict_types=1);

namespace App\Actions\Purchasing;

use App\Enums\PurchaseStatus;
use App\Enums\StockMovementType;
use App\Enums\StockSourceType;
use App\Exceptions\Domain\InvalidStockDocument;
use App\Models\Purchase;
use App\Models\StockBatch;
use App\Models\User;
use App\Models\Vendor;
use App\Support\Database\Transaction;
use App\Support\Money\Money;
use App\Support\Stock\StockLedger;
use App\Support\Tenancy\TenantContext;
use LogicException;

/**
 * Cancels a received purchase of the current branch. Allowed only while every batch it created is
 * untouched (qty_remaining = qty_received) and no payment is allocated to it (a vendorless purchase is settled on receipt and has no payment rows). The batches are
 * emptied through the ledger (type purchase, negative qty) and vendors.balance −= total.
 */
final readonly class CancelPurchase
{
    public function __construct(private StockLedger $ledger, private TenantContext $context) {}

    public function handle(User $actor, Purchase $purchase): Purchase
    {
        return Transaction::run(function () use ($actor, $purchase): Purchase {
            $branch = $this->context->branch();
            $purchase = Purchase::query()->findOrFail($purchase->id);
            $this->assertCancellable($purchase);

            $items = $purchase->items()->with('product')->get();
            $products = [];
            foreach ($items as $item) {
                $product = $item->product ?? throw new LogicException(sprintf('Purchase item %d has no product.', $item->id));
                if ($product->track_stock) {
                    $products[$product->id] = $product;
                }
            }

            // Locking order: branch_stocks → stock_batches → vendors → the purchase itself.
            $this->ledger->lock($branch, array_values($products));

            $batches = StockBatch::query()
                ->where('source_type', StockSourceType::Purchase)
                ->whereIn('source_id', $items->pluck('id')->all())
                ->orderBy('received_at')
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('source_id');

            foreach ($batches as $batch) {
                if (! $batch->qty_remaining->equals($batch->qty_received)) {
                    throw InvalidStockDocument::stockConsumed($purchase->number);
                }
            }

            $vendor = $purchase->vendor_id === null ? null : Vendor::query()->whereKey($purchase->vendor_id)->lockForUpdate()->firstOrFail();
            $purchase = Purchase::query()->whereKey($purchase->id)->lockForUpdate()->firstOrFail();
            $this->assertCancellable($purchase);

            foreach ($items as $item) {
                $batch = $batches->get($item->id);
                if (! $batch instanceof StockBatch || ! isset($products[$item->product_id])) {
                    continue;
                }
                $this->ledger->issue(
                    $products[$item->product_id],
                    $branch,
                    $batch->qty_received,
                    StockMovementType::Purchase,
                    ['type' => 'purchase_items', 'id' => $item->id, 'user_id' => $actor->id, 'note' => 'cancelled'],
                    $batch,
                    allowNegative: false,
                );
            }

            if ($vendor instanceof Vendor) {
                $vendor->balance = $vendor->balance->minus($purchase->due);
                $vendor->save();
            }

            $purchase->status = PurchaseStatus::Cancelled;
            $purchase->due = Money::zero();
            $purchase->updated_by = $actor->id;
            $purchase->save();

            return $purchase;
        });
    }

    private function assertCancellable(Purchase $purchase): void
    {
        if ($purchase->status !== PurchaseStatus::Received) {
            throw InvalidStockDocument::notReceived($purchase->number);
        }

        if ($purchase->vendor_id !== null && ! $purchase->paid->isZero()) {
            throw InvalidStockDocument::hasPayments($purchase->number);
        }
    }
}
