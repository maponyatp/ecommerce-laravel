<?php

namespace App\Services;

use App\Models\InventoryLog;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\ProductVariantDraft;
use App\Models\User;
use App\Support\StoreMoney;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class ProductVariantPublicationService
{
    public function values(ProductVariant $variant): array
    {
        return $variant->only(['sku', 'title', 'options', 'price', 'currency', 'active', 'inventory_quantity', 'weight', 'version']);
    }

    public function publish(Product $product, ProductVariantDraft $draft, array $input, User $actor): ProductVariant
    {
        abort_unless($actor->hasAnyRole(['admin', 'super_admin']), 403);
        foreach (['viewAny', 'view', 'update'] as $ability) {
            Gate::forUser($actor)->authorize($ability, $ability === 'viewAny' ? Product::class : $product);
        }
        $data = Validator::make($input, [
            'draft_version' => 'required|integer|min:1', 'version' => 'required|integer|min:0',
            'inventory_quantity' => 'required|integer|min:0|max:9999999',
            'weight' => 'required|numeric|min:0|max:999999.99', 'active' => 'required|boolean',
        ])->validate();

        try {
            return DB::transaction(function () use ($product, $draft, $data, $actor) {
                $product = Product::lockForUpdate()->findOrFail($product->id);
                Gate::forUser($actor)->authorize('update', $product);
                $draft = ProductVariantDraft::where('product_id', $product->id)->lockForUpdate()->findOrFail($draft->id);
                $variant = ProductVariant::where('draft_id', $draft->id)->lockForUpdate()->first();
                $fail = fn (string $message) => throw ValidationException::withMessages(['inventory_quantity' => $message]);
                if ($draft->version !== (int) $data['draft_version'] || ($variant?->version ?? 0) !== (int) $data['version']) {
                    $fail('The draft or live stock changed. Close and reopen this form to review the latest values.');
                }
                if ($product->is_downloadable || ($product->pricing_type && $product->pricing_type !== 'fixed')) {
                    $fail('Purchasable variants currently support fixed-price physical products only.');
                }
                if ($draft->currency !== StoreMoney::currency() || ($draft->archived && $data['active'])) {
                    $fail('Restore the draft and use the store currency before publishing it for sale.');
                }
                if ($variant && ($variant->sku !== $draft->sku || $variant->options !== $draft->options)) {
                    $fail('A published SKU and its options identify physical stock and cannot be replaced. Create a new draft for a different option.');
                }
                if (ProductVariant::whereRaw('LOWER(sku) = ?', [strtolower($draft->sku)])
                    ->when($variant, fn ($query) => $query->where('id', '!=', $variant->id))->exists()) {
                    $fail('This SKU is already used by a live variant.');
                }
                if (! $product->has_variants && DB::table('stock_reservations')->where('product_id', $product->id)
                    ->where('variant_key', 0)->whereNull('released_at')->whereNull('committed_at')->where('expires_at', '>', now())->lockForUpdate()->exists()) {
                    $fail('This product has an active simple-product checkout. Wait for it to settle or expire before publishing variants.');
                }
                $before = $variant ? $this->values($variant) : null;
                $oldStock = $variant?->inventory_quantity ?? 0;
                $reserved = $variant ? $oldStock - app(StockReservationService::class)->available($product, lock: true, variant: $variant) : 0;
                if ((int) $data['inventory_quantity'] < $reserved) {
                    $fail('On-hand stock cannot be lower than the quantity reserved by active checkouts ('.$reserved.').');
                }
                $variant ??= new ProductVariant;
                $variant->forceFill([
                    'product_id' => $product->id, 'draft_id' => $draft->id,
                    'sku' => $draft->sku, 'title' => $draft->title, 'options' => $draft->options,
                    'price' => $draft->price, 'currency' => $draft->currency,
                    'active' => (bool) $data['active'], 'inventory_quantity' => (int) $data['inventory_quantity'],
                    'weight' => $data['weight'], 'weight_unit' => 'kg', 'requires_shipping' => true,
                    'inventory_policy' => 'deny', 'version' => ($variant->version ?? 0) + 1,
                ])->save();
                $product->forceFill(['has_variants' => true])->save();
                DB::table('product_variant_publications')->insert([
                    'product_variant_id' => $variant->id, 'actor_id' => $actor->id, 'draft_version' => $draft->version,
                    'before_values' => $before ? json_encode($before, JSON_THROW_ON_ERROR) : null,
                    'after_values' => json_encode($this->values($variant->fresh()), JSON_THROW_ON_ERROR), 'created_at' => now(),
                ]);
                if ($oldStock !== (int) $data['inventory_quantity']) {
                    InventoryLog::create(['product_id' => $product->id, 'product_variant_id' => $variant->id,
                        'quantity_change' => (int) $data['inventory_quantity'] - $oldStock,
                        'old_quantity' => $oldStock, 'new_quantity' => $data['inventory_quantity'], 'reason' => 'variant_publication',
                        'reference_id' => $actor->id, 'reference_type' => User::class]);
                }

                return $variant->fresh();
            }, 3);
        } catch (UniqueConstraintViolationException $exception) {
            throw ValidationException::withMessages(['inventory_quantity' => 'This SKU or draft was published by another editor. Reopen the form.']);
        }
    }
}
