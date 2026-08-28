<?php

namespace App\Services;

use App\Models\DownloadableProduct;
use App\Models\Order;
use App\Models\Product;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class DigitalFulfillmentService
{
    public static function isPrivateProductPath(?string $path): bool
    {
        return $path !== null && str_starts_with($path, 'downloadable_products/')
            && ! str_contains($path, '..') && ! str_contains($path, '\\')
            && ! preg_match('/[\x00-\x1F]/', $path);
    }

    public function issue(Order $order): void
    {
        DB::transaction(function () use ($order) {
            $order = Order::query()->lockForUpdate()->findOrFail($order->id);
            if ($order->payment_status !== 'paid' || in_array($order->status, ['cancelled', 'refunded'], true)) {
                return;
            }

            foreach ($order->items()->with('product')->lockForUpdate()->get() as $item) {
                // Never renew an expired/used entitlement on a repeated callback.
                if ($item->download_path || ! $item->product?->is_downloadable) {
                    continue;
                }
                $source = $this->availableSource($item->product);
                if (! $source) {
                    continue;
                }

                $expires = $item->download_expires_at ?: ($order->payment_processed_at ?: $order->created_at)->copy()->addDays(30);
                if ($source['expires_at']) {
                    $expires = $expires->min($source['expires_at']);
                }
                $item->update([
                    'download_path' => $source['path'],
                    'download_link' => $item->download_link ?: Str::random(64),
                    'download_expires_at' => $expires,
                    'download_limit' => $source['limit'],
                ]);
            }
        }, 3);
    }

    public function availableSource(Product $product): ?array
    {
        if (! $product->is_downloadable) {
            return null;
        }
        $legacy = $product->downloadable_file ? null : DownloadableProduct::where('product_id', $product->id)->first();
        $path = $product->downloadable_file ?: $legacy?->file_url;
        $expiry = $product->expiration_time ?: $legacy?->expiration_time;
        $expiry = $expiry ? Carbon::parse($expiry) : null;
        $limit = $product->download_limit ?? $legacy?->download_limit;
        if (! self::isPrivateProductPath($path) || ! Storage::disk('local')->exists($path)
            || ($expiry && $expiry->isPast()) || ($limit !== null && $limit < 1)) {
            return null;
        }

        return ['path' => $path, 'limit' => $limit, 'expires_at' => $expiry];
    }
}
