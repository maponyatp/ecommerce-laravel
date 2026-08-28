# Commerce gap closure — 27 August 2026

Latest admin UX and encrypted iKhokha/DSV settings release: [Admin workspace release](admin-workspace-2026-08-27.md). DSV credential storage is available; automated courier services remain unimplemented.

## Fixed in this pass

- Payment return URLs now require expiring signatures; an unsigned order ID cannot produce a private confirmation link.
- Payment returns, callbacks, and signed order/invoice pages remain available when the admin storefront switch is off.
- Browser returns never mark an order paid. An unchanged checkout cart is cleared only for the matching paid order; later cart changes are preserved.
- Checkout reloads current product prices, names, weights, stock, and physical/digital status from the database.
- Shipping requirements are derived server-side; unavailable shipping methods and invalid quantities are rejected before order creation.
- Coupons are revalidated against the current subtotal and expiry, rather than using a previously saved discount. Discounts cannot exceed the subtotal.
- Coupon order relationships use the actual coupon-code field; usage counts include paid orders, not failed payment attempts. Simultaneous checkout reservation of limited coupons remains a separate concurrency concern.
- Orders and their items are created within a database transaction.
- Added missing recipient and inventory-audit columns used by checkout. Historical audit values remain nullable rather than being invented.
- Fixed the missing confirmation-notification import and incorrect product-category relationship in free/legacy checkout.
- Gateway exceptions are no longer shown verbatim to customers.
- Admin invoices now have a View / Print action; failed payments no longer display as pending on confirmation pages.

## Verification

The targeted suite covers unsigned/expired/tampered return URLs, pending returns, cart preservation, forged shipping flags, invalid quantities, unavailable gateways, expired/stale coupons, complete free checkout, invoice access isolation, and a successful signed webhook while the storefront is closed. Replaying the success callback creates one invoice and one stock deduction. A later failure callback cannot undo a paid payment.

The focused suite passed 47 tests / 122 assertions on the final code. A further admin invoice-table rendering test passed 1 test / 2 assertions (48 tests / 124 assertions combined). The frontend build and local database migrations also completed. Re-run the same suite when extending checkout.

## Deployment

Deployed to `shop.maponya-tech.com` on 27 August 2026. Database and code backup: `/var/backups/flowershop/gap-20260827-052035`. Both new migrations ran successfully; configuration, route, and view caches rebuilt; PHP-FPM is active and the site is out of maintenance mode.

Live HTTP checks: health, homepage, products, admin login, and the new CSS asset returned 200. Unauthenticated admin invoice/order pages redirected to login (302); an unsigned webhook returned 403. The sample return URL used a nonexistent order and returned 404, so signature rejection for existing orders is covered by local tests, with signed middleware confirmed in the production route listing. No production.ERROR entries dated 27 August were found at the end of these checks. No live payment, refund, or customer-order mutation was submitted.

## Follow-up: digital delivery and account ownership

- Digital fulfillment now uses the private file configured in the admin product editor. Legacy `downloadable_products` metadata is supported only for files under the same private product directory.
- Paid order items receive an independent file path, access token, download limit and expiry. The default access period is 30 days from payment (or historical order creation), capped by the configured product expiry. Replays and page refreshes do not reset existing limits or renew expired access.
- Download URLs are signed for at most five minutes and require the order item's token. Guest customers can obtain them from their private confirmation link; signed-in customers can use their authorized order page. Receipt links now last 30 days, without extending the entitlement's own expiry.
- The download endpoint rechecks payment, cancellation/refund state, expiry, allowance and private path, and locks the order/item while consuming the allowance. HEAD requests and missing-file responses do not consume allowance. A download counts an authorized GET attempt, not a confirmed complete transfer. Concurrency was implemented using database row locks; the automated suite used SQLite and does not constitute a MySQL load test.
- Asynchronous payment success and free/legacy checkout issue digital access before sending the receipt. Product pages no longer construct malformed download routes. Admin upload guidance and download-limit validation are clearer. Checkout rejects missing/expired digital files before creating an order or starting payment.
- New signed-in checkout orders retain explicit account ownership via `orders.user_id`. Email changes cannot transfer them. Guest/legacy orders with no account owner still require a verified match to the receipt email. No historical accounts/orders were automatically assigned. Email verification/resend is enabled, and profile email changes revoke verification.
- A repeated success for an already-paid transaction no longer reverses a later refund. Failure of another payment attempt cannot downgrade a paid/refunded order.

Verification: 79 tests / 228 assertions passed across digital fulfillment, checkout, invoice/order access, coupons, payment gateway and order-model suites. The new digital regression file includes 22 tests. Frontend build, local migration and view compilation passed. The build still reports an existing JavaScript chunk-size warning.

Operational notes: configure digital files under **Admin → Products → Downloadable Product**. File updates/limit changes affect newly issued access; retain old private files while customers have active entitlements. Previously issued legacy records without file snapshots are initialized on authorized access without extending their existing expiry. Refund state must still be reconciled with the gateway; this patch does not implement money movement or partial-item refund entitlement revocation.

This follow-up was deployed on 27 August 2026 with backup `/var/backups/flowershop/digital-20260827-1`. Migration `000010` ran successfully, caches rebuilt, queue restart signalled, and PHP-FPM remained active. Live health/homepage/catalogue/admin login/customer login/CSS checks returned 200. Guest order, invoice, verification and legacy-download requests redirected to login (302). The sample new download ID did not exist and returned 404; existing-record rejection is covered by the local suite, and production signed/throttled route middleware was confirmed. An unsigned payment webhook returned 403. No dated production.ERROR entries were found in `storage/logs/laravel.log` at the final check. No live payments, refunds, orders or test customer accounts were created during deployment checks.

## Still open — not a production-completeness certification

The later shop-management release adds guarded staff fulfilment, private notes/history/tracking, central delivery-method management, postal-code enforcement and currency-filtered operational reports. See `shop-management-gap-audit-2026-08-27.md` for the feature matrix, 144-test verification and remaining work. This does not complete refunds, reservations, DSV, flower delivery scheduling or customer CRM.

- Merchant iKhokha credentials and a merchant-approved end-to-end live transaction/refund test are outstanding. Mocked gateway tests are not proof of live settlement.
- The international-customer follow-up standardises new checkout charges/display in ZAR and snapshots order currency. Legacy/admin analytics and other inactive financial modules still need a mixed-currency aggregation audit. See `international-storefront-2026-08-27.md` for scope and verification.
- Checkout now uses an explicit delivery country and taxes discounted merchandise, rather than the legacy US address parser. Tax-inclusive pricing, product-class/shipping/compound taxes, digital taxes, jurisdiction configuration and invoice business details still need merchant review before financial go-live.
- Refund::process() is bookkeeping, not evidence that a gateway refund was executed. Refund verification, credit notes, partial returns, and restocking need an integrated workflow.
- Digital delivery now has a tested protected path. File-retention/version management, partial-item refund revocation and production concurrency/load testing remain separate work.
- Supplier dispatch and fulfillment notifications are not consistently integrated with asynchronous payments.
- A later release implements 30-minute stock/coupon holds, idempotent checkout identity, shared settlement and durable receipt recovery; see `checkout-recovery-2026-08-27.md`. Provider-side expiry/payment reconciliation, MySQL concurrency testing, other purchase paths and inbox-delivery monitoring remain open.
- New storefront orders have explicit account ownership; guest/legacy access retains a verified-email fallback. A historical ownership-claim/migration workflow and a separate audit of all team-panel/API resources remain to be completed.

Do not describe all commerce gaps as closed based on this patch.
