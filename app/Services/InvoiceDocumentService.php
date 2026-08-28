<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\Order;
use App\Settings\GeneralSettings;
use App\Support\StoreBranding;
use Illuminate\Support\Facades\DB;

class InvoiceDocumentService
{
    public function issue(Order $order): Invoice
    {
        return DB::transaction(function () use ($order): Invoice {
            // Settlement uses the same lock order; two issuers cannot create different documents.
            $order = Order::query()->lockForUpdate()->findOrFail($order->id);
            if ($existing = Invoice::where('order_id', $order->id)->first()) {
                return $existing; // Never reconstruct or backdate a historical invoice.
            }
            $invoice = new Invoice([
                'order_id' => $order->id, 'customer_id' => $order->customer_id,
                'invoice_number' => 'INV-'.now()->format('Y').'-'.str_pad((string) $order->id, 6, '0', STR_PAD_LEFT),
                'invoice_date' => now(), 'total_amount' => $order->total_amount, 'payment_status' => $order->payment_status,
            ]);
            $invoice->document_snapshot = $this->capture($order, $invoice);
            $invoice->save();

            return $invoice;
        }, 3);
    }

    private function capture(Order $order, Invoice $invoice): array
    {
        $context = $order->invoice_context ?? $this->context();
        // Older orders have no evidence of the tax classification at checkout.
        if ($order->invoice_context === null) {
            $context['vat_status'] = 'unconfirmed';
        }

        return $this->orderDetails($order, $invoice) + [
            'version' => 2,
            'issued_at' => $invoice->invoice_date->toIso8601String(),
            'buyer' => $order->billing_details,
        ] + $context;
    }

    public function context(): array
    {
        $settings = app(GeneralSettings::class);
        $branding = app(StoreBranding::class)->invoiceSnapshot();

        return [
            'branding' => $branding,
            'vat_status' => $settings->invoice_vat_status,
            'footer_note' => $settings->invoice_footer_note,
            'seller' => [
                'name' => filled($settings->invoice_seller_name) ? $settings->invoice_seller_name : $settings->site_name,
                'address' => filled($settings->invoice_seller_address) ? $settings->invoice_seller_address : $branding['address'],
                'registration_number' => $settings->invoice_registration_number,
                'tax_number' => $settings->invoice_tax_number,
                'email' => $branding['email'], 'phone' => $branding['phone'],
            ],
        ];
    }

    public function taxPresentation(array $document): array
    {
        $status = $document['vat_status'] ?? 'unconfirmed';
        $seller = $document['seller'] ?? [];
        $buyer = $document['buyer'] ?? [];
        $issues = [];
        if (empty($seller['name']) || empty($seller['address'])) {
            $issues[] = 'Complete seller identity and address were not recorded.';
        }
        if ($status === 'unconfirmed') {
            $issues[] = 'VAT registration and tax treatment were not confirmed for this order. This document must not be used to substantiate an input VAT claim.';
        } elseif ($status === 'not_registered') {
            if ((float) $document['totals']['tax'] !== 0.0) {
                $issues[] = 'A tax amount was charged although the seller was marked not VAT registered. Contact the store for review; no VAT claim is supported.';
            }
        } elseif ($status === 'registered') {
            if (! preg_match('/^\d{10}$/', $seller['tax_number'] ?? '')) {
                $issues[] = 'A valid-format supplier VAT registration number was not recorded.';
            }
            // Always produce full details, including for zero-rated and low-value supplies.
            if (empty($buyer['name']) || empty($buyer['address'])) {
                $issues[] = 'Full invoice recipient name and billing address were not recorded.';
            }
            if (($buyer['is_vat_vendor'] ?? false) && ! preg_match('/^\d{10}$/', $buyer['vat_number'] ?? '')) {
                $issues[] = 'The VAT-registered recipient needs a VAT registration number.';
            }
            if (($document['currency'] ?? null) !== 'ZAR') {
                $issues[] = 'South African rand values were not recorded; a reviewed VAT conversion is required.';
            }
            $items = collect($document['items'] ?? []);
            if (empty($document['issued_at']) || $items->isEmpty()
                || $items->contains(fn ($item) => empty($item['name']) || ($item['quantity'] ?? 0) <= 0)) {
                $issues[] = 'The issue date or complete supply descriptions and quantities are missing.';
            }
            if (round($items->sum(fn ($item) => (float) ($item['amount'] ?? 0)), 2) !== round((float) $document['totals']['subtotal'], 2)) {
                $issues[] = 'Line amounts do not reconcile to the recorded subtotal. Contact the store for a reviewed correction.';
            }
        }

        return [
            'title' => $status === 'registered' && $issues === [] ? 'Tax Invoice' : 'Invoice',
            'tax_label' => $status === 'registered' ? 'VAT (as charged)' : 'Tax (as charged)',
            'status' => $status,
            'issues' => $issues,
        ];
    }

    public function document(Invoice $invoice): array
    {
        if ($invoice->document_snapshot !== null) {
            return $invoice->document_snapshot + ['legacy' => false];
        }

        // Read-only compatibility. Do not invent past seller details from today's settings.
        return $this->orderDetails($invoice->order, $invoice) + ['version' => 0, 'legacy' => true,
            'issued_at' => $invoice->invoice_date?->toIso8601String(), 'seller' => null];
    }

    private function orderDetails(Order $order, Invoice $invoice): array
    {
        return [
            'number' => $invoice->invoice_number ?: '#'.$invoice->id,
            'order_id' => $order->id,
            'currency' => $order->currency,
            'customer_email' => $order->customer_email,
            // Shipping/recipient details are not evidence of the buyer's billing identity.
            'delivery_address' => $order->shipping_address,
            'payment_method' => $order->payment_method,
            'items' => $order->items()->orderBy('id')->get()->map(fn ($item) => [
                'name' => filled($item->product_name_snapshot) ? $item->product_name_snapshot
                    : 'Product #'.$item->product_id.' (original name not recorded)',
                'quantity' => (int) $item->quantity,
                'sku' => $item->sku_snapshot,
                'options' => $item->options_snapshot,
                'unit_price' => $this->decimal($item->price),
                'amount' => $this->decimal((float) $item->price * (int) $item->quantity),
            ])->all(),
            'totals' => [
                'subtotal' => $this->decimal((float) $invoice->total_amount - (float) $order->shipping_cost - (float) $order->tax_amount + (float) $order->discount_amount),
                'shipping' => $this->decimal($order->shipping_cost), 'tax' => $this->decimal($order->tax_amount),
                'discount' => $this->decimal($order->discount_amount), 'total' => $this->decimal($invoice->total_amount),
            ],
        ];
    }

    private function decimal(mixed $amount): string
    {
        return number_format((float) $amount, 2, '.', '');
    }
}
