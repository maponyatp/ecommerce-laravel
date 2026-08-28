<?php

namespace App\Services;

use App\Models\Product;
use App\Models\ShippingMethod;
use App\Services\Payments\IkhokhaGateway;
use App\Settings\GeneralSettings;
use App\Support\StoreBranding;
use Illuminate\Support\Facades\Schema;

class StoreReadinessService
{
    public function report(): array
    {
        $settings = app(GeneralSettings::class);
        $physical = Product::where('is_downloadable', false)->orWhereNull('is_downloadable')->exists();
        $checks = [
            $this->check('https', 'HTTPS store address', str_starts_with((string) config('app.url'), 'https://'), 'Set the public application URL to the verified HTTPS store address.'),
            $this->check('debug', 'Debug output disabled', ! config('app.debug'), 'Disable debug output on the production server.'),
            $this->check('contact_email', 'Customer support email', filled(app(StoreBranding::class)->current()['email']), 'Set a real monitored Site Email in Store details. Seeded example.com addresses are not customer support contacts.'),
            $this->check('invoice_schema', 'Invoice snapshot storage', Schema::hasColumn('invoices', 'document_snapshot'), 'Apply the reviewed invoice migration before accepting new orders.'),
            $this->check('seller', 'Invoice seller identity', filled($settings->invoice_seller_name) && filled($settings->invoice_seller_address), 'Review and save the actual legal seller name and business address in Business & invoices.'),
            $this->check('vat_status', 'VAT registration decision', $settings->invoice_vat_status === 'not_registered'
                || ($settings->invoice_vat_status === 'registered' && preg_match('/^\d{10}$/', $settings->invoice_tax_number ?? '') === 1),
                'Confirm VAT status and, if registered, the issued 10-digit VAT number in Business & invoices. This is not verification of the tax calculation.'),
            $this->check('catalogue', 'Store catalogue exists', Product::exists(), 'Add actual products before inviting customers. Check prices, available stock, options and digital files; a catalogue entry alone does not prove it can be purchased.'),
            $this->check('payments', 'Payment gateway credentials', app(IkhokhaGateway::class)->isConfigured()
                || (filled(config('services.stripe.key')) && filled(config('services.stripe.secret'))), 'Configure a supported merchant account. Credentials alone do not verify settlement.'),
            $this->check('delivery', 'Delivery method for physical products', ! $physical || $this->hasAvailableDelivery(), 'Physical products require an active method with valid rates, an explicit maximum weight and valid postal codes. Scheduled methods also need an open window with remaining capacity. Confirm coverage for your actual products and customers.'),
        ];
        $reviews = [
            'Test actual catalogue items from product selection through checkout, including stock, variants and digital-file delivery. Catalogue presence alone is not a purchase test.',
            'Confirm a merchant-approved payment, receipt and refund, including duplicate and late callbacks.',
            'Review seller identity, invoice/tax treatment, policies and customer consent for the intended market.',
            'VAT vendors: obtain accountant sign-off on tax-inclusive advertised prices, delivery and digital-product VAT, exemptions, invoice issue timing, credit notes and records retention. Invoice branding does not implement these tax workflows.',
            'Test last-item and delivery-capacity contention on production-like MySQL, plus mobile checkout and accessibility.',
            'Verify admin MFA, least-privilege access, secret rotation, monitoring, mail delivery and a successful backup restore.',
        ];
        if ($physical) {
            $reviews[] = 'Complete a real fulfilment acceptance test. DSV settings do not activate quotes, bookings, labels or tracking.';
        }

        return ['checks' => $checks, 'blocked_count' => count(array_filter($checks, fn ($check) => ! $check['passed'])),
            'manual_reviews' => $reviews, 'production_certified' => false,
            'scope' => 'One store per deployment. Simple physical and digital products; ZAR checkout and South African delivery. Purchasable variants, subscriptions, marketplaces and multi-currency settlement are not certified or completed by this check.'];
    }

    private function check(string $id, string $label, bool $passed, string $action): array
    {
        return compact('id', 'label', 'passed', 'action');
    }

    private function hasAvailableDelivery(): bool
    {
        return app(ShippingService::class)->getAvailableShippingMethods()->contains(
            fn (ShippingMethod $method) => ! $method->requires_delivery_slot
                || app(DeliverySchedulingService::class)->choices($method->id)->isNotEmpty()
        );
    }
}
