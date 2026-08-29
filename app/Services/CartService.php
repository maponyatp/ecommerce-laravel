<?php

namespace App\Services;

use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Support\StoreMoney;
use Illuminate\Support\Facades\Session;
use Illuminate\Validation\ValidationException;

class CartService
{
    public function quantity(mixed $value): int
    {
        if ((! is_int($value) && (! is_string($value) || ! ctype_digit($value)))
            || (int) $value < 1 || (int) $value > 9999) {
            throw ValidationException::withMessages(['cart' => 'Quantity must be a whole number from 1 to 9999.']);
        }

        return (int) $value;
    }

    private function productId(mixed $value): int
    {
        if ((! is_int($value) && (! is_string($value) || ! ctype_digit($value)))
            || filter_var($value, FILTER_VALIDATE_INT) === false || (int) $value < 1) {
            throw ValidationException::withMessages(['cart' => 'An item has an invalid product reference. Please clear your cart.']);
        }

        return (int) $value;
    }

    /** Canonical keys are a product ID or v:product-ID:variant-ID. Never trust session metadata. */
    public function reference(mixed $key): array
    {
        if (is_string($key) && preg_match('/^v:([1-9][0-9]*):([1-9][0-9]*)$/D', $key, $parts)) {
            return [$this->productId($parts[1]), $this->productId($parts[2])];
        }

        return [$this->productId($key), null];
    }

    private function key(mixed $key): int|string
    {
        [$productId, $variantId] = $this->reference($key);

        return $variantId ? "v:$productId:$variantId" : $productId;
    }

    private function resolve(mixed $key): array
    {
        [$productId, $variantId] = $this->reference($key);
        $product = Product::find($productId);
        $variant = $variantId ? ProductVariant::where('product_id', $productId)->find($variantId) : null;

        return [$product, $variant, $variantId];
    }

    public function current(): array
    {
        $cart = Session::get('cart', []);
        if (! is_array($cart)) {
            throw ValidationException::withMessages(['cart' => 'Your saved cart is invalid. Please clear your cart and try again.']);
        }

        return $cart;
    }

    private function details(Product $product, int $quantity, ?ProductVariant $variant = null): array
    {
        if ($variant) {
            $options = collect($variant->options ?? [])->map(fn ($value, $name) => "$name: $value")->implode(', ');

            return ['name' => $product->name.' — '.$variant->title.($options ? ' ('.$options.')' : ''),
                'price' => $variant->price, 'quantity' => $quantity, 'is_downloadable' => false,
                'weight' => (float) ($variant->weight ?? $product->weight ?? 0),
                'product_variant_id' => $variant->id, 'sku_snapshot' => $variant->sku, 'options_snapshot' => $variant->options];
        }

        // A fixed allowlist: browser/session metadata never determines prices, shipping or digital delivery.
        return ['name' => $product->name, 'price' => $product->base_store_price, 'quantity' => $quantity,
            'is_downloadable' => $product->is_downloadable, 'weight' => (float) ($product->weight ?? 0)];
    }

    private function availabilityError(Product $product, int $quantity, ?ProductVariant $variant = null, ?int $variantId = null): ?string
    {
        if (! $product->supportsStorePricing()) {
            return 'This product pricing option is not available at checkout. Please contact the store or remove the item.';
        }
        if ($product->has_variants && ! $variantId) {
            return 'Choose an option on the product page before adding this product.';
        }
        if ($variantId && (! $product->has_variants || ! $variant || ! $variant->active
            || $variant->currency !== StoreMoney::currency() || $product->is_downloadable
            || ($product->pricing_type && $product->pricing_type !== 'fixed'))) {
            return 'This product option is no longer available. Please remove it or choose another option.';
        }
        $price = $variant?->price ?? $product->base_store_price;
        if (! is_numeric($price) || ! is_finite((float) $price) || (float) $price < 0) {
            return 'This product has an unavailable price. Please remove it or contact the store.';
        }
        if (app(StockReservationService::class)->available($product, variant: $variant) < $quantity) {
            return 'Requested quantity exceeds available stock, including active checkout reservations. Reduce the quantity or remove this item.';
        }
        if ($product->is_downloadable && ! app(DigitalFulfillmentService::class)->availableSource($product)) {
            return 'A digital item is currently unavailable for delivery. Please remove it or contact the store.';
        }

        return null;
    }

    public function line(mixed $productId, mixed $quantity): array
    {
        $quantity = $this->quantity($quantity);
        [$product, $variant, $variantId] = $this->resolve($productId);
        if (! $product) {
            throw ValidationException::withMessages(['cart' => 'This product is no longer available. Please remove it from your cart.']);
        }
        if ($error = $this->availabilityError($product, $quantity, $variant, $variantId)) {
            throw ValidationException::withMessages(['cart' => $error]);
        }

        return $this->details($product, $quantity, $variant);
    }

    public function hydrate(array $cart): array
    {
        $resolved = [];
        foreach ($cart as $id => $item) {
            $resolved[$this->key($id)] = $this->line($id, is_array($item) ? ($item['quantity'] ?? null) : null);
        }

        return $resolved;
    }

    public function add(mixed $productId, mixed $quantity = 1, mixed $variantId = null): void
    {
        $id = $this->productId($productId);
        if ($variantId !== null && $variantId !== '') {
            $id = 'v:'.$id.':'.$this->productId($variantId);
        }
        $quantity = $this->quantity($quantity);
        $cart = $this->current();
        $existing = array_key_exists($id, $cart) ? $this->quantity(is_array($cart[$id]) ? ($cart[$id]['quantity'] ?? null) : null) : 0;
        $cart[$id] = $this->line($id, $existing + $quantity);
        $this->save($cart);
    }

    public function update(mixed $productId, mixed $quantity): void
    {
        $id = $this->key($productId);
        $cart = $this->current();
        if (! array_key_exists($id, $cart)) {
            throw ValidationException::withMessages(['cart' => 'Product not found in cart.']);
        }
        $cart[$id] = $this->line($id, $quantity);
        $this->save($cart);
    }

    public function remove(mixed $productId): void
    {
        $id = $this->key($productId);
        $cart = $this->current();
        if (! array_key_exists($id, $cart)) {
            throw ValidationException::withMessages(['cart' => 'Product not found in cart.']);
        }
        unset($cart[$id]);
        $this->save($cart);
        if ($cart === []) {
            Session::forget('coupon');
        }
    }

    private function save(array $cart): void
    {
        Session::put('cart', $cart);
        Session::forget('checkout_quote');
        // Preserve pending-payment references and checkout idempotency keys.
    }

    public function clear(): void
    {
        Session::forget(['cart', 'coupon', 'checkout_quote']);
    }

    public function canResumeCheckout(): bool
    {
        $key = Session::get('checkout_last_key');
        $cart = Session::get('cart', []);
        if (! is_string($key) || $key === '' || ! is_array($cart) || $cart === []
            || Session::get('checkout_last_cart') !== hash('sha256', serialize($cart))) {
            return false;
        }

        // Match the existing checkout resume gate. This link does not create another order or bypass stock validation.
        return Order::where('checkout_key', $key)->where(fn ($query) => $query->where('status', 'payment_review')
            ->orWhere(fn ($held) => $held->where('stock_reservation_status', 'held')->where('stock_reserved_until', '>', now())))->exists();
    }

    /** Read-only display: retain unavailable rows for removal, never rewrite a pending checkout's cart hash. */
    public function snapshot(): array
    {
        $items = [];
        $errors = [];
        try {
            $cart = $this->current();
        } catch (ValidationException $exception) {
            return ['items' => [], 'errors' => $exception->validator->errors()->all()];
        }
        foreach ($cart as $id => $item) {
            try {
                $id = $this->key($id);
            } catch (ValidationException $exception) {
                $errors = array_merge($errors, $exception->validator->errors()->all());

                continue;
            }
            [$product, $variant, $variantId] = $this->resolve($id);
            $quantity = is_array($item) ? ($item['quantity'] ?? null) : null;
            try {
                $quantity = $this->quantity($quantity);
                $error = $product ? $this->availabilityError($product, $quantity, $variant, $variantId) : 'This product is no longer available. Remove it to continue.';
            } catch (ValidationException $exception) {
                $quantity = 0;
                $error = $exception->validator->errors()->first();
            }
            $row = $product ? $this->details($product, $quantity, $variant) : [
                'name' => 'Unavailable product #'.$id, 'price' => null, 'quantity' => $quantity,
                'is_downloadable' => false, 'weight' => 0,
            ];
            if ($error) {
                $errors[] = $row['name'].': '.$error;
            }
            $items[$id] = $row + ['issue' => $error];
        }

        return ['items' => $items, 'errors' => array_values(array_unique($errors))];
    }
}
