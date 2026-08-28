<?php

namespace App\Support;

use App\Models\Product;
use App\Services\StoreReadinessService;

/** Reviewed deployed workflows, not a list inferred from unfinished models. */
class AdminFeatureGuide
{
    public function content(): array
    {
        $report = app(StoreReadinessService::class)->report();

        return [
            'reviewed_at' => now()->timezone('Africa/Johannesburg')->format('d M Y, H:i').' SAST',
            'product_count' => Product::count(),
            'modules' => [
                ['title' => 'Dashboard & setup', 'path' => '/admin', 'details' => 'Use the grouped admin workspace for store operations, catalogue, online store, delivery, marketing and settings. The setup guide links to contact details, business identity, branding, products, payments and delivery. Visibility depends on your permissions.'],
                ['title' => 'Products & purchasable options', 'path' => '/admin/products', 'details' => 'Manage product descriptions, images, categories, prices and availability. Physical fixed-price options can have their own SKU, price, weight and stock. Prepare and publish options such as bouquet size or colour; review them on the storefront before selling. Digital-product support is separate and still needs merchant acceptance testing.'],
                ['title' => 'Inventory & flower wastage', 'path' => '/admin/inventory', 'details' => 'Review on-hand stock, checkout holds and available stock for products and published options. Record receipts, counted quantities, damaged or wilted flowers and other adjustments with reasons. View history and export private stock reports. Active reservations are protected. Supplier purchase orders, batch expiry and multiple stock locations are not complete.'],
                ['title' => 'Store design & central branding', 'path' => '/admin/manage-general-settings', 'details' => 'Upload the store logo centrally; set the store name, colours, contact information, homepage content and section settings. Supported branding is reused across the storefront, admin, emails and new invoice snapshots. Use PNG or JPEG for email compatibility. Homepage changes are immediate; this is not a full drag-and-drop theme builder.'],
                ['title' => 'CMS pages, navigation & SEO', 'path' => '/admin/pages', 'details' => 'Create page drafts, use private previews, explicitly publish or unpublish, and restore a revision as a draft. Manage navigation menus and parent links, FAQs and supported SEO metadata. Published pages have storefront URLs. Menu/homepage revision history and automatic URL redirects are not included.'],
                ['title' => 'Cart & checkout', 'path' => '/products', 'details' => 'Customers browse the catalogue, select published options and use the cart and checkout. Prices are calculated on the server, with stock holds and checkout recovery safeguards. Separate billing details and South African delivery information are supported. The supported checkout currency is ZAR; international pricing and settlement are not certified.'],
                ['title' => 'Payments & transaction review', 'path' => '/admin/store-integrations', 'details' => 'Super administrators can securely configure or disable iKhokha. Transaction records and manual provider-status verification support payment review. An optional Stripe checkout path also exists, but is not merchant-verified. Real merchant credentials and payment/settlement tests are still required. PayFast, Peach Payments and Ozow are requested additions, not enabled production gateways in this release.'],
                ['title' => 'Orders & fulfilment', 'path' => '/admin/orders', 'details' => 'Review orders, payment states, private staff notes and fulfilment stages. Use packing documents and dispatch references for eligible orders. Customer order history and receipts are available. Phone/WhatsApp order creation, safe post-payment editing and split shipments are not complete.'],
                ['title' => 'Invoices & business identity', 'path' => '/admin/invoices', 'details' => 'Print or save invoices as PDF. New invoice documents preserve seller, buyer, branding and order details instead of changing when store settings change. Configure legal seller details and VAT status first. Branded invoice emails use private expiring links. An accountant must approve VAT treatment and document compliance.'],
                ['title' => 'Returns & refunds', 'path' => '/admin/returns', 'details' => 'Staff can record and manage physical-return intake. Completing a return does not return money or put stock back. The new external-refund recording and credit-note workflow remains outside this deployed email release; automated gateway refunds are not available. Coordinate any real refund through the merchant provider and retain evidence.'],
                ['title' => 'Delivery scheduling', 'path' => '/admin/delivery-operations', 'details' => 'Manage local delivery methods, rates, slots, capacity, booking review and dispatch information. DSV credentials can be stored securely, but DSV quotes, courier bookings, labels and automated tracking are not operational. Confirm delivery coverage, flower handling and fees before accepting orders.'],
                ['title' => 'Customers & support', 'path' => '/admin/customer-directory', 'details' => 'Use the customer directory, order-support cases and chat support workspace to assist customers. Staff access and private customer documents are permission-controlled. Test the customer journey, support responses and privacy boundaries with your team.'],
                ['title' => 'Discounts, coupons & reporting', 'path' => '/admin/reports', 'details' => 'Manage supported discounts and coupons, and review operational sales/order reports. These reports are not full accounting statements or verified margin/fee reconciliation. Abandoned-checkout marketing, gift cards, loyalty, recurring subscriptions and external product feeds must not be assumed complete.'],
                ['title' => 'Staff accounts & operational controls', 'path' => '/admin/store-readiness', 'details' => 'Existing staff accounts and roles control admin access; password reset and branded transactional notifications are available. Deployment backups, checkout recovery and configuration checks support operations. New staff security-audit/firewall controls and portal SMTP editing remain pending release. MFA enforcement, restore drills, load testing and alerting still need acceptance evidence.'],
            ],
            'checks' => $report['checks'],
            'blocked_count' => $report['blocked_count'],
            'acceptance' => [
                'Complete real support contact, legal business details, VAT review, logo, delivery coverage and customer-facing policies.',
                'Add actual products with photos, prices, stock and bouquet options; verify desktop and mobile pages, menus and checkout totals.',
                'Use provider sandbox/test mode first. Only perform an agreed live payment after merchant configuration; check the callback, order, receipt, invoice and settlement.',
                'Agree a refund test and retain provider evidence. A return record is not proof that money was refunded.',
                'Test a real delivery only after confirming rates, capacity and courier arrangements. DSV credentials alone do not activate delivery.',
                'Check these emails in the inbox and spam folder. SMTP acceptance does not prove inbox delivery.',
                'Verify staff permissions, MFA, backups/restoration, monitoring and realistic concurrent checkout before public launch.',
            ],
        ];
    }
}
