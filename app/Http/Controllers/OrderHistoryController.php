<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Services\DigitalFulfillmentService;
use Illuminate\Support\Facades\Auth;

class OrderHistoryController extends Controller
{
    public function index()
    {
        $user = Auth::user();
        $orders = Order::accessibleTo($user)
            ->orderBy('created_at', 'desc')
            ->paginate(10);

        return view('orders.history', compact('orders'));
    }

    public function show($id)
    {
        $order = Order::findOrFail($id);

        // Ensure the order belongs to the authenticated user
        if (! $order->isAccessibleTo(Auth::user())) {
            abort(403, 'Unauthorized action.');
        }

        app(DigitalFulfillmentService::class)->issue($order);
        $order->load(['items.product', 'shippingMethod', 'invoice']);

        return view('orders.show', compact('order'));
    }
}
