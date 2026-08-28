<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Services\OrderSupportService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;

class OrderSupportController extends Controller
{
    private function authorizeOrder(Request $request, Order $order): void
    {
        abort_unless($request->hasValidSignature() || ($request->user() && $order->isAccessibleTo($request->user())), 403);
    }

    public function show(Request $request, Order $order)
    {
        $this->authorizeOrder($request, $order);
        $order->load('items.product');
        $cases = $order->issues()->latest()->paginate(10);
        $active = $order->issues()->whereNotNull('active_order_id')->first();

        return response()->view('orders.support', compact('order', 'cases', 'active'))
            ->header('Cache-Control', 'private, no-store')->header('Referrer-Policy', 'no-referrer');
    }

    public function store(Request $request, Order $order, OrderSupportService $support)
    {
        $this->authorizeOrder($request, $order);
        $data = $request->validate(['action' => 'required|in:open,reply', 'issue_id' => 'required_if:action,reply|nullable|integer']);
        // A guest bearer-link user is not impersonated as the account owner.
        $customer = $request->user() && $order->isAccessibleTo($request->user()) ? $request->user() : null;
        $issue = $data['action'] === 'reply'
            ? $support->reply($order, (int) $data['issue_id'], $request->all(), $customer)
            : $support->open($order, $request->all(), $customer);

        return redirect()->to(URL::temporarySignedRoute('order-support.show', now()->addMinutes(30), ['order' => $order->id]))
            ->with('success', 'Support case #'.$issue->id.' updated. Replies appear on this private page. No refund or replacement is automatically approved.');
    }
}
