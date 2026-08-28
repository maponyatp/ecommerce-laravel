<?php

namespace App\Http\Controllers;

use App\Models\Invoice;
use App\Models\Order;
use App\Services\InvoiceDocumentService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class InvoiceController extends Controller
{
    public function createInvoiceForOrder(int $orderId): Invoice
    {
        $order = Order::findOrFail($orderId);

        return app(InvoiceDocumentService::class)->issue($order);
    }

    public function index(Request $request): View
    {
        $query = Invoice::query()
            ->whereHas('order', fn ($orders) => $orders->accessibleTo(Auth::user()));
        if ($request->has('date')) {
            $query->whereDate('invoice_date', $request->date);
        }
        if ($request->has('status')) {
            $query->where('payment_status', $request->status);
        }
        $invoices = $query->latest()->paginate(10);

        return view('invoices.index', compact('invoices'));
    }

    public function show(int $id): View
    {
        $invoice = Invoice::with(['order.items.product', 'order.shippingMethod'])->findOrFail($id);
        abort_unless($invoice->order->isAccessibleTo(Auth::user()), 403);

        return view('invoices.show', compact('invoice'));
    }

    public function print(Invoice $invoice): View
    {
        $invoice->load(['order.items.product', 'order.shippingMethod']);

        return view('invoices.show', ['invoice' => $invoice, 'printMode' => true]);
    }
}
