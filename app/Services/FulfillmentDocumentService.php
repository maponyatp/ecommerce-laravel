<?php

namespace App\Services;

use App\Models\Order;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

class FulfillmentDocumentService
{
    public const STATES = ['open' => 'Not yet delivered', 'unfulfilled' => 'Unfulfilled', 'processing' => 'Preparing',
        'shipped' => 'Dispatched', 'delivered' => 'Delivered', 'all' => 'All eligible states'];

    public function eligibleOrders(): Builder
    {
        return Order::query()->where('payment_status', 'paid')->whereNotNull('inventory_committed_at')
            ->whereDoesntHave('refunds', fn ($refunds) => $refunds->whereNotNull('version')->where('status', 'pending'))
            ->whereIn('status', ['processing', 'completed'])->where('is_dropshipped', false)
            ->whereIn('shipping_status', ['unfulfilled', 'processing', 'shipped', 'delivered'])
            ->whereNotNull('shipping_address')->where('shipping_address', '!=', '')
            ->whereHas('items', fn ($items) => $items->where('is_downloadable_snapshot', false)
                ->orWhere(fn ($legacy) => $legacy->whereNull('is_downloadable_snapshot')->whereNull('download_path')
                    ->whereHas('product', fn ($product) => $product->where('is_downloadable', false))))
            ->where(function ($query) {
                $query->whereNull('delivery_slot_id')->orWhereHas('deliveryBooking', fn ($booking) => $booking->where('status', 'confirmed')
                    ->whereColumn('delivery_bookings.delivery_slot_id', 'orders.delivery_slot_id'));
            });
    }

    public function canPrint(Order $order): bool
    {
        return $this->eligibleOrders()->whereKey($order->id)->exists();
    }

    public function dailyOrders(string $date, ?int $method, string $state): Builder
    {
        $day = CarbonImmutable::createFromFormat('!Y-m-d', $date, config('commerce.delivery_timezone'));

        return $this->eligibleOrders()->whereNotNull('delivery_slot_id')
            ->where('delivery_scheduled_at', '>=', $day->utc())
            ->where('delivery_scheduled_at', '<', $day->addDay()->utc())
            ->when($method, fn ($query) => $query->where('shipping_method_id', $method))
            ->when($state === 'open', fn ($query) => $query->where('shipping_status', '!=', 'delivered'))
            ->when(! in_array($state, ['open', 'all'], true), fn ($query) => $query->where('shipping_status', $state))
            ->with(['shippingMethod', 'deliveryBooking'])->orderBy('delivery_scheduled_at')->orderBy('id');
    }
}
