<?php

namespace App\Services;

use App\Models\InventoryLog;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class InventoryManagementService
{
    public const REASONS = ['received' => 'Stock received', 'count' => 'Physical stock count', 'damaged' => 'Damaged stock', 'expired' => 'Expired / wilted flowers', 'correction' => 'Stock correction'];

    public function adjust(Product $product, ?int $variantId, array $input, User $actor): Product|ProductVariant
    {
        abort_unless($actor->hasAnyRole(['admin', 'super_admin']), 403);
        Gate::forUser($actor)->authorize('update', $product);
        $input['reason'] = trim($input['reason'] ?? '');
        $data = Validator::make($input, [
            'mode' => ['required', Rule::in(['adjust', 'set'])],
            'quantity' => 'required|integer|between:-2147483647,2147483647',
            'expected_quantity' => 'required|integer|min:0|max:2147483647',
            'reason' => 'required|string|max:255',
        ])->validate();

        return DB::transaction(function () use ($product, $variantId, $data, $actor) {
            // Same lock order as checkout and publication: product, option, reservations.
            $product = Product::lockForUpdate()->findOrFail($product->id);
            Gate::forUser($actor)->authorize('update', $product);
            $fail = fn (string $message) => throw ValidationException::withMessages(['quantity' => $message]);
            if ((bool) $product->has_variants !== (bool) $variantId) {
                $fail('Choose a published option for variant stock. Parent stock is not sold.');
            }
            $variant = $variantId ? ProductVariant::where('product_id', $product->id)->whereNotNull('draft_id')->lockForUpdate()->findOrFail($variantId) : null;
            $old = (int) ($variant ? $variant->inventory_quantity : $product->inventory_count);
            if ($old !== (int) $data['expected_quantity']) {
                $fail('Stock changed since you opened this form. Close and reopen it before saving.');
            }
            $new = $data['mode'] === 'set' ? (int) $data['quantity'] : $old + (int) $data['quantity'];
            if ($new < 0 || $new > 2147483647) {
                $fail('On-hand stock must be between 0 and 2147483647.');
            }
            $reserved = (int) DB::table('stock_reservations')->where('product_id', $product->id)
                ->where('variant_key', $variantId ?? 0)->whereNull('released_at')->whereNull('committed_at')
                ->where('expires_at', '>', now())->lockForUpdate()->get()->sum('quantity');
            if ($new < $reserved) {
                $fail("There are {$reserved} units reserved by active checkouts. Stock cannot fall below that quantity. Review those checkouts before recording this loss.");
            }
            if ($new === $old) {
                return $variant ?? $product;
            }
            if ($variant) {
                $variant->forceFill(['inventory_quantity' => $new, 'version' => $variant->version + 1])->save();
            } else {
                $product->update(['inventory_count' => $new]);
            }
            InventoryLog::create([
                'product_id' => $product->id, 'product_variant_id' => $variantId,
                'quantity_change' => $new - $old, 'old_quantity' => $old, 'new_quantity' => $new,
                'reason' => $data['reason'], 'reference_id' => $actor->id, 'reference_type' => User::class,
            ]);

            return ($variant ?? $product)->fresh();
        }, 3);
    }
}
