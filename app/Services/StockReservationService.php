<?php

namespace App\Services;

use App\Models\Coupon;
use App\Models\InventoryLog;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Support\StoreMoney;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class StockReservationService
{
    public function available(Product $product, ?int $exceptOrder = null, bool $lock = false, ?ProductVariant $variant = null): int
    {
        $holds = DB::table('stock_reservations')->where('product_id', $product->id)
            ->where('variant_key', $variant?->id ?? 0)
            ->whereNull('released_at')->whereNull('committed_at')->where('expires_at', '>', now());
        if ($exceptOrder) {
            $holds->where('order_id', '!=', $exceptOrder);
        }
        // Read current rows, not an earlier repeatable-read snapshot, after locking the product.
        $reserved = $lock ? $holds->lockForUpdate()->get()->sum('quantity') : $holds->sum('quantity');

        return max(0, ($variant ? $variant->inventory_quantity : $product->inventory_count) - $reserved);
    }

    /** Called inside order creation's transaction. No remote requests while locks are held. */
    public function reserve(Order $order): void
    {
        if (DB::transactionLevel() === 0) {
            throw new \LogicException('Stock reservation requires an order transaction.');
        }
        if ($order->coupon_code) {
            $coupon = Coupon::where('code', $order->coupon_code)->lockForUpdate()->first();
            $subtotal = (float) $order->items()->selectRaw('SUM(price * quantity) as subtotal')->value('subtotal');
            if (! $coupon || ! $coupon->isValid()
                || ($coupon->min_purchase_amount && $subtotal < $coupon->min_purchase_amount)
                || app(CouponService::class)->calculateDiscount($coupon, $subtotal) !== round((float) $order->discount_amount, 2)) {
                throw ValidationException::withMessages(['coupon' => 'This coupon is no longer available. Review your cart.']);
            }
        }
        $expires = now()->addMinutes((int) config('commerce.reservation_minutes', 30));
        foreach ($order->items()->orderBy('product_id')->orderBy('product_variant_id')->get() as $item) {
            $product = Product::query()->lockForUpdate()->find($item->product_id);
            $variant = $item->product_variant_id ? ProductVariant::where('product_id', $item->product_id)->lockForUpdate()->find($item->product_variant_id) : null;
            if (! $product || ! $product->supportsStorePricing() || $item->quantity < 1 || ($item->product_variant_id && (! $variant || ! $variant->active
                || ! $product->has_variants || $variant->currency !== $order->currency || $variant->currency !== StoreMoney::currency()
                || $product->is_downloadable || ($product->pricing_type && $product->pricing_type !== 'fixed')))
                || (! $item->product_variant_id && $product->has_variants)
                || $this->available($product, $order->id, true, $variant) < $item->quantity) {
                throw ValidationException::withMessages(['cart' => 'An item was just reserved by another customer. Please review your cart.']);
            }
            if (round((float) ($variant?->price ?? $product->base_store_price), 2) !== round((float) $item->price, 2)) {
                throw ValidationException::withMessages(['cart' => 'A product price changed. Please review the updated total.']);
            }
            DB::table('stock_reservations')->insert([
                'order_id' => $order->id, 'product_id' => $product->id, 'variant_key' => $variant?->id ?? 0, 'quantity' => $item->quantity,
                'expires_at' => $expires, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        $order->update(['stock_reserved_until' => $expires, 'stock_reservation_status' => 'held']);
    }

    /** Order must be locked by the settlement transaction. All-or-nothing stock commitment. */
    public function commit(Order $order): bool
    {
        if (DB::transactionLevel() === 0) {
            throw new \LogicException('Stock commitment requires a settlement transaction.');
        }
        if ($order->inventory_committed_at) {
            return true;
        }
        $items = $order->items()->orderBy('product_id')->orderBy('product_variant_id')->get()
            ->groupBy(fn ($item) => $item->product_id.':'.($item->product_variant_id ?? 0))->map(fn ($rows) => (object) [
                'product_id' => $rows->first()->product_id, 'product_variant_id' => $rows->first()->product_variant_id, 'quantity' => $rows->sum('quantity'),
            ]);
        $products = [];
        $variants = [];
        foreach ($items as $key => $item) {
            $product = Product::query()->lockForUpdate()->find($item->product_id);
            // A later catalogue deactivation must not prevent fulfilling an already paid option.
            $variant = $item->product_variant_id ? ProductVariant::where('product_id', $item->product_id)->lockForUpdate()->find($item->product_variant_id) : null;
            if (! $product || ($item->product_variant_id && ! $variant) || $item->quantity < 1
                || $this->available($product, $order->id, true, $variant) < $item->quantity) {
                $this->release($order);

                return false;
            }
            $products[$key] = $product;
            $variants[$key] = $variant;
        }
        foreach ($items as $key => $item) {
            $product = $products[$key];
            $variant = $variants[$key];
            $old = $variant ? $variant->inventory_quantity : $product->inventory_count;
            if ($variant) {
                $variant->inventory_quantity -= $item->quantity;
                $variant->version++;
                $variant->save();
            } else {
                $product->decrement('inventory_count', $item->quantity);
            }
            InventoryLog::create([
                'product_variant_id' => $variant?->id,
                'product_id' => $product->id, 'quantity_change' => -$item->quantity,
                'old_quantity' => $old, 'new_quantity' => $old - $item->quantity, 'reason' => 'order',
                'reference_id' => $order->id, 'reference_type' => Order::class,
            ]);
        }
        DB::table('stock_reservations')->where('order_id', $order->id)->whereNull('committed_at')
            ->update(['committed_at' => now(), 'updated_at' => now()]);
        $order->update(['inventory_committed_at' => now(), 'stock_reservation_status' => 'committed']);

        return true;
    }

    public function release(Order $order): void
    {
        app(DeliverySchedulingService::class)->release($order);
        DB::table('stock_reservations')->where('order_id', $order->id)->whereNull('committed_at')
            ->whereNull('released_at')->update(['released_at' => now(), 'updated_at' => now()]);
        if (! $order->inventory_committed_at) {
            $order->update(['stock_reservation_status' => 'released']);
        }
    }

    public function expire(int $limit = 100): int
    {
        $ids = Order::where('stock_reservation_status', 'held')->where('stock_reserved_until', '<=', now())
            ->orderBy('id')->limit($limit)->pluck('id');
        $count = 0;
        foreach ($ids as $id) {
            $count += DB::transaction(function () use ($id) {
                $order = Order::lockForUpdate()->find($id);
                if (! $order || $order->stock_reservation_status !== 'held' || $order->stock_reserved_until->isFuture()
                    || $order->payment_status === 'paid') {
                    return 0;
                }
                $this->release($order);
                // Expired inventory hold does NOT prove payment failed or cancel a gateway link.
                if (in_array($order->status, ['pending', 'awaiting_payment'], true)) {
                    $order->update(['status' => 'checkout_expired']);
                }

                return 1;
            }, 3);
        }

        return $count;
    }
}
