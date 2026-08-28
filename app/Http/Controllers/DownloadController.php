<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Services\DigitalFulfillmentService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class DownloadController extends Controller
{
    public function generateSecureLink(Request $request, string $category, string $product)
    {
        $product = Product::where('slug', $product)->first()
            ?? Product::findOrFail(ctype_digit($product) ? $product : 0);
        abort_unless((string) $product->category_id === $category || $product->category?->slug === $category, 404);

        $orders = Order::accessibleTo($request->user())->where('payment_status', 'paid')
            ->whereHas('items', fn ($items) => $items->where('product_id', $product->id))->latest()->get();
        foreach ($orders as $order) {
            app(DigitalFulfillmentService::class)->issue($order);
            foreach ($order->items()->where('product_id', $product->id)->get() as $item) {
                if ($url = $item->downloadUrl()) {
                    return response()->json([
                        'url' => $url,
                        'expires_in' => min(300, (int) now()->diffInSeconds($item->download_expires_at)),
                        'downloads_remaining' => $item->download_limit === null ? null : $item->download_limit - $item->download_count,
                    ])->header('Cache-Control', 'private, no-store');
                }
            }
        }
        abort(403, 'No active paid download is available for this account.');
    }

    public function serveFile(Request $request, string $category, string $product)
    {
        $response = $this->generateSecureLink($request, $category, $product);

        return redirect()->to($response->getData()->url);
    }

    public function download(Request $request, OrderItem $item)
    {
        return DB::transaction(function () use ($request, $item) {
            // Same lock order as payment finalization: order, then order item.
            $order = Order::query()->lockForUpdate()->findOrFail($item->order_id);
            $item = OrderItem::query()->lockForUpdate()->findOrFail($item->id);
            $item->setRelation('order', $order);
            $token = $request->query('token');
            abort_unless(is_string($token) && $item->download_link && hash_equals($item->download_link, $token), 403);
            abort_unless($item->isDownloadValid(), 403, 'This download has expired, reached its limit, or is no longer authorized.');
            abort_unless(DigitalFulfillmentService::isPrivateProductPath($item->download_path), 404);
            $disk = Storage::disk('local');
            abort_unless($disk->exists($item->download_path), 404, 'The file is unavailable. Please contact the store.');
            $response = $disk->download($item->download_path, basename($item->download_path), [
                'Content-Type' => 'application/octet-stream',
                'Cache-Control' => 'private, no-store',
                'Referrer-Policy' => 'no-referrer',
                'X-Content-Type-Options' => 'nosniff',
            ]);
            // HEAD probes must not spend a customer's download allowance.
            if (! $request->isMethod('HEAD')) {
                $item->increment('download_count');
            }

            return $response;
        }, 3);
    }
}
