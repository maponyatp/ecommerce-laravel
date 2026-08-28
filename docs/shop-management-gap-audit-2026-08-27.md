# Shop-management gap audit — 27 August 2026

Current commercial-platform scope and release gates: [Commercial platform roadmap](commercial-platform-roadmap-2026-08-27.md). The latest milestone adds stable invoice snapshots, seller settings and launch checks; it does not complete purchasable variants, refunds or universal store-type support.

## Scope and verdict

“Spotify” was interpreted as **Shopify**, using the user's earlier Shopify/OpenCart comparison. This is a workflow comparison, not a Shopify integration or a claim of feature parity.

The flower shop has useful CMS, catalogue and checkout foundations, but **not every commerce gap is closed**. Existing database models do not prove that an admin module, checkout integration, permissions, notifications or payment lifecycle is complete. The table below distinguishes those states.

Follow-up: `checkout-recovery-2026-08-27.md` documents the subsequent stock/coupon holds, repeat-submission protection, shared settlement and receipt-outbox recovery release (163 backend tests plus four JavaScript checks). This closes part of the reservation/recovery work below, not provider reconciliation, strict late-payment expiry or SMTP inbox-delivery monitoring.

## Capability matrix

| Capability | Evidence / current state | Remaining work |
| --- | --- | --- |
| Branding, home sections, CMS pages and menus | Existing admin resources/settings and prior regression coverage | Full visual/mobile/accessibility regression; content preview/versioning/rollback |
| Products, categories, stock and low-stock thresholds | Existing product/category resources | Variant selection through cart/payment; stock reservations, inventory-adjustment concurrency, imports and multi-location reconciliation |
| Discounts and coupons | Existing resources; coupon revalidation in checkout | Atomic limited-coupon reservation; discount stacking/policy decisions |
| Orders and fulfilment | **Improved in this release:** items, gift message, recipient, guarded transitions, tracking, private notes and history | Partial fulfilment, cancellation/payment reconciliation, automatic courier booking, packing slips |
| Delivery methods and areas | **Added central admin module:** rates, estimates, activation and exact ZA postal-code limits; checkout enforces them | DSV credentials/product docs, rate/label API, verified service coverage |
| Flower-specific delivery scheduling | Recipient and gift message available; delivery estimates editable | Delivery-date/slot selection, capacity, same-day cutoff, blackout dates, perishability/substitution policies |
| Invoices and receipts | Existing signed private invoice/receipt views and currency snapshots | Business/tax identifiers, credit notes, accounting exports and merchant-reviewed tax treatment |
| Payments | iKhokha adapter and guarded callbacks; mocked regression coverage | Merchant credentials, real settlement/refund tests, retries/reconciliation and modern Stripe flow review |
| Returns and refunds | Follow-up deployed staff item-level return intake, approval, partial receipts and audit history; unverified Refund::process shortcut blocked (see return-operations-2026-08-27.md) | Gateway-confirmed refunds, credit notes, replacement/customer notifications and separate reviewed inventory decisions remain open; completing return handling never means money moved |
| Customer management | User admin exists; storefront orders hold account/receipt ownership | Unified guest/account customer directory, consent-aware profiles, order history and ownership-safe merging |
| Sales and inventory reporting | **Improved:** known-currency paid-order totals, real item sales, exclusions disclosed, true low-stock count | Accounting-grade net sales/refunds, all legacy customer analytics, timezone/date-period review and export reconciliation |
| Email and operations | Existing receipt/reset notifications | Durable outbox/retry/recovery, bounce/delivery monitoring, fulfilment notifications; this release does not send delivery emails |
| Search and discovery | Product metadata, sitemap/CMS foundations exist | Merchant Center/marketplace setup, feed validation, search-quality review and translations |
| International customers | ZAR checkout and structured ZA delivery address in previous release | Explicit foreign-market decisions, payment acceptance, tax/policies and localization; no overseas flower shipping enabled |
| Optional advanced features | Variant, bundle, gift card, loyalty, subscription, abandoned-cart and return models exist | End-to-end activation/permissions/accounting tests; do not label these modules complete based on models alone |
| Security and operations | Admin gates, signed private documents, CSRF and signed webhook protections in covered workflows | All API/team resource authorization audit, load/concurrency tests, restore drill, queue monitoring, backup retention and security review |

## Changes in this release

### Orders

- Admin → Commerce → Orders → Manage now shows line items, gift details, recipient/contact information, shipping/tax/discount values and a stock-commit indicator.
- Removed arbitrary order-status editing. A server-side service permits Unfulfilled → Preparing → Dispatched → Delivered, one step at a time. “Delivered” completes the order.
- Verified paid status and committed inventory are mandatory; cancelled/refunded/stock-review orders cannot progress.
- Dispatch requires a courier/local delivery team and a tracking/dispatch reference. An optional HTTPS tracking link appears on authorized customer/signed confirmation pages; unsafe legacy URLs are not rendered.
- Row locking and a version check reject stale staff edits. Repeating the same saved state does not duplicate the audit record.
- Private staff notes retain author/time, are escaped, and are not included on customer order/confirmation pages. Fulfilment history retains staff author/time; it does not claim a delivery email was sent.
- Order payment, totals and currency are not writable through the fulfilment service.
- No cancellation, refund, restocking or DSV booking is performed by the fulfilment controls. Digital-only orders remain on their existing protected fulfilment workflow.

### Delivery management

- Admin → Commerce → Delivery methods supports creation/editing, rates, delivery estimates, activation and exact four-digit ZA postal-code lists (leading zeroes retained).
- New methods start disabled in the admin form. Existing configuration is preserved.
- Empty postal-code lists mean all ZA postal codes, subject to the existing country/weight rules; merchants must enter their actual served areas. No coverage or rate was invented.
- Disabled, negative-rate, over-weight and outside-area methods are rejected when quoting/submitting physical checkout.
- The legacy /shipping management page is now administrator-only and redirects to the central admin module.
- Legacy DELETE requests deactivate a method rather than deleting it, preserving historical order references.

### Reports

- Dashboard sales metrics/trends and top-product monetary aggregates include only paid orders in the current store currency. Other/unknown-currency orders are disclosed as excluded in the dashboard.
- Paid-order totals include order charges; item sales are labelled before order-level discounts. Neither is presented as accounting net revenue.
- Removed fabricated dashboard sparkline data and corrected the previous-period comparison boundary.
- Inventory value is labelled retail value, not cost of goods. The low-stock count is no longer capped by the 20-row preview list.
- Reports validate cube/group/measure against server-owned lists before building queries.
- Item-sales reports read actual paid, known-currency order items (up to 200 groups). Unverified legacy customer monetary measures are no longer offered; engagement/customer counts still depend on their separate collectors.

## Verification

Final combined regression suite: **144 tests / 458 assertions passed**. Four checkout JavaScript logic tests also passed.
These include actual Livewire admin creation/edit/save calls, two sequential fulfilment saves, stale update rejection, permission checks, protected notes, safe tracking, delivery deactivation/postal-code enforcement, currency-separated analytics and report input validation.

The production asset build succeeded with the existing large-JavaScript-chunk warning. Local migration 000012 and view compilation succeeded. The in-app browser could not attach its webview; visual browser/mobile verification remains incomplete. SQLite tests do not establish MySQL concurrency under load. No live customer order, refund, shipment or email was generated by this work.

## Production release

Deployed to `https://shop.maponya-tech.com` after verifying the database and code backup at `/var/backups/flowershop/operations-20260827-1`.

- Release archive SHA-256: `a2b76bf43b392195eecdd307232dbca8cfbfb68e77ac145c8a8789689296ce51`.
- Migration `2026_08_27_000012_add_shop_operations_controls` ran successfully. Configuration, routes and views rebuilt; queue restart requested; PHP-FPM reloaded.
- The application is out of maintenance mode; nginx and PHP-FPM are active. The production build manifest matches the local release.
- HTTP checks returned 200 for health, home, catalogue, admin login and new stylesheets. Guest requests to orders, delivery methods, reports, legacy shipping management and empty-cart checkout returned 302. A quote POST without CSRF returned 419; an unsigned payment webhook returned 403.
- Production route inspection confirms authentication/admin middleware on legacy shipping management and panel authentication on the new delivery resource. Local automated tests cover authenticated rendering/saving; no production order was edited for testing.
- Existing rates, postal-code coverage, payment credentials, customer accounts and legal policies were not changed. No live shipment, payment, refund or customer notification was created.

## Prioritized next work

Runtime/database follow-up: `runtime-database-repairs-2026-08-27.md` records the deployed CMS import, catalogue/header/OAuth error handling, review vote schema, inventory transaction, settings uniqueness, payment-reference validation and fragment-link repairs. The configured full suite passed 981 tests / 3,140 assertions; the additional header regression and 11 JavaScript tests also passed. Production checks found no orphaned records across 188 foreign keys and no new logged application exceptions. This does not certify zero defects or complete the payment/DSV/variant work below.

Subsequent releases: `delivery-scheduling-2026-08-27.md` records opt-in delivery windows, capacity/cutoff protection and paid-order rebooking. `order-support-2026-08-27.md` records customer/staff after-sales cases and visible admin validation. Neither adds DSV bookings or verified refunds.

Further follow-up: `fulfillment-documents-2026-08-27.md` records deployed staff-only packing slips, daily confirmed-delivery lists and new-checkout product-name/type snapshots. The basic packing-document gap is closed; partial fulfilment, automatic DSV booking and verified refunds remain open. Combined commerce coverage reached 220 tests / 867 assertions plus seven JavaScript tests, with local browser document checks.

Customer follow-up: `customer-directory-2026-08-27.md` records the deployed read-only Customers module with identity-separated order/support history and currency-separated paid totals. This closes purchase-history discovery, not profile editing, marketing consent or account merging. Coverage reached 236 tests / 949 assertions plus seven JavaScript checks, with local browser search/profile verification.

Internal-profile follow-up: `private-customer-profiles-2026-08-27.md` adds editable staff-only names, labels and notes, audit revisions and stale-edit protection. Account ownership, delivery details and marketing consent remain unchanged. Coverage reached 251 tests / 1,026 assertions plus seven JavaScript checks; a local browser save and before/after audit view were verified.

Directory-search follow-up: `customer-directory-search-2026-08-27.md` adds internal-name search, exact-label filtering and profile badges across all paginated results, preserving identity boundaries and keeping notes out of search. Coverage reached 261 tests / 1,073 assertions plus seven JavaScript checks; eight synthetic strict-MySQL checks and local browser filtering/reset/profile navigation passed. This release does not establish large-dataset performance or complete CRM parity.

Cart-integrity follow-up: `cart-integrity-2026-08-27.md` unifies server-owned cart pricing/metadata, quantity and stock-hold validation across HTTP/Livewire/checkout, locks client cart state, preserves pending-checkout recovery and fixes the legacy product-card add action. The selected commerce suite reached 296 tests / 1,310 assertions plus seven JavaScript checks. Local browser verified add, totals, quantity buttons and removal after temporary local preview limits were raised; performance and complete browser/input coverage remain unverified. This fixes a prerequisite cart gap, not the variant/options checkout module.

Variant-draft follow-up: see variant-drafts-2026-08-27.md for an isolated admin planning catalogue for physical fixed-price product options, proposed SKUs/prices, archiving, audit revisions and stale-edit protection. Selected regression coverage: 68 tests / 388 assertions. **Drafts are not purchasable variants**; storefront selection, variant stock/checkout and publishing remain open. Browser visual verification was unavailable in this pass.

Access-security follow-up: see store-access-and-comparison-2026-08-27.md. Deployed account/session-bound customer chat, admin replies, protected management APIs with role/permission/token-scope checks, missing token storage and working product comparison. Selected coverage: 126 tests / 734 assertions. Live health/comparison checks passed. Production has neither iKhokha nor Stripe configured, so paid checkout remains blocked. This does not complete DSV, live variants, refunds or full security/visual verification.

1. Stock/coupon holds and durable receipt recovery were added in the follow-up. Remaining: MySQL concurrency testing, provider-side expiry/payment reconciliation, other purchase paths and receipt monitoring.
2. Verified refunds/credit notes and cancellation/payment races, including merchant-approved gateway testing. Staff physical-return intake/approval/partial receipts/audit are covered by `return-operations-2026-08-27.md` (115 selected tests / 610 assertions); they do not move money or restock flowers. Unverified legacy refund processing is now blocked.
3. Flower-delivery dates, capacity/cutoffs and a DSV integration backed by the correct account's API documentation.
4. Unified customer management and the complete variant/product-option checkout path.
5. Full browser/mobile/accessibility/security/load/restore verification, then optional loyalty/gift-card/subscription/marketing modules.

## References

- [Shopify managing orders](https://help.shopify.com/en/manual/fulfillment/managing-orders): reference for the scope of order/payment/return workflows.
- [Shopify fulfilment](https://help.shopify.com/en/manual/fulfillment): reference for delivery workflow and tracking.
- [Shopify refunding orders](https://help.shopify.com/en/manual/fulfillment/managing-orders/refunding-orders): distinguishes payment refunds from restocking/returns.
- [Shopify inventory management](https://help.shopify.com/en/manual/products/inventory): inventory availability and replenishment reference.

These references informed the comparison; they do not establish that this shop has Shopify's capabilities.
