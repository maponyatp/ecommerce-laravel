<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\ShippingMethod;
use App\Services\FulfillmentDocumentService;
use App\Settings\GeneralSettings;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class FulfillmentDocumentController extends Controller
{
    private function privateView(string $view, array $data)
    {
        return response()->view($view, [...$data, 'branding' => app(GeneralSettings::class),
            'generatedAt' => now()->timezone(config('commerce.delivery_timezone'))])
            ->header('Cache-Control', 'private, no-store, max-age=0')
            ->header('Referrer-Policy', 'no-referrer')->header('X-Robots-Tag', 'noindex, nofollow, noarchive');
    }

    public function packingSlip(Request $request, Order $order, FulfillmentDocumentService $documents)
    {
        Gate::authorize('view', $order);
        abort_unless($documents->canPrint($order), 409,
            'Packing documents require paid, stock-committed physical orders without payment, delivery or supplier review. Use the order screen to resolve issues first.');
        $order->load('items.product', 'shippingMethod', 'deliveryBooking');
        $legacyDetails = $order->items->contains(fn ($item) => $item->product_name_snapshot === null || $item->is_downloadable_snapshot === null);

        return $this->privateView('operations.packing-slip', compact('order', 'legacyDetails'));
    }

    public function deliveryList(Request $request, FulfillmentDocumentService $documents)
    {
        Gate::authorize('viewAny', Order::class);
        $data = $request->validate([
            'date' => 'sometimes|required|date_format:Y-m-d',
            'shipping_method_id' => 'nullable|integer|exists:shipping_methods,id',
            'state' => ['sometimes', 'required', Rule::in(array_keys(FulfillmentDocumentService::STATES))],
            'print' => 'sometimes|boolean',
        ]);
        $date = $data['date'] ?? now()->timezone(config('commerce.delivery_timezone'))->toDateString();
        $state = $data['state'] ?? 'open';
        $method = filled($data['shipping_method_id'] ?? null) ? (int) $data['shipping_method_id'] : null;
        $printMode = $request->boolean('print');
        $query = $documents->dailyOrders($date, $method, $state);
        if ($printMode) {
            $orders = $query->limit(501)->get();
            abort_if($orders->count() > 500, 422, 'More than 500 deliveries match. Filter by delivery method or fulfilment state before printing.');
            $total = $orders->count();
        } else {
            $orders = $query->paginate(50)->withQueryString();
            $total = $orders->total();
        }
        $unscheduled = $documents->eligibleOrders()->whereNull('delivery_slot_id')->where('shipping_status', '!=', 'delivered')->count();
        $methods = ShippingMethod::orderBy('name')->get(['id', 'name']);
        $states = FulfillmentDocumentService::STATES;

        return $this->privateView('operations.delivery-list', compact('orders', 'total', 'date', 'state', 'method', 'printMode', 'unscheduled', 'methods', 'states'));
    }
}
