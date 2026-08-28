# Flower delivery scheduling — 27 August 2026

## Scope and activation

This release adds opt-in, explicit delivery windows for a flower shop. It does not represent Shopify feature parity, a carrier booking, a route-planning system, or permission to deliver internationally.

1. In Commerce → Delivery calendar, create future date/time windows and a booking cutoff in South African time.
2. Set the maximum number of deliveries/orders for each window. A multi-bouquet order consumes one place.
3. Publish only windows the delivery team can actually fulfil.
4. In Commerce → Delivery methods, enable “Require a delivery window” on the appropriate method.
5. Unpublish all windows for a day to close that date to new bookings. Existing paid bookings and unexpired holds are not cancelled.

All existing methods default to scheduling off; no production windows or bookings are seeded. Rates, service areas and payment credentials are unchanged. Each window and method has separate capacity; overlapping windows do not share a driver-capacity pool. No recurring calendar or bulk holiday rule is claimed.

## Lifecycle and protections

- Checkout quotes and final creation validate the selected window, method, availability and cutoff.
- The order transaction locks the slot and reserves one place alongside its stock reservation. A competing checkout rolls back completely if capacity has gone.
- Holds expire at the earlier of the stock hold expiry or booking cutoff. Expired holds stop counting immediately, regardless of recovery timer timing.
- Verified failure and stock-reservation expiry release held delivery bookings. No on-hand stock is added by a release.
- Settlement confirms the delivery hold once. Late payments reacquire a place only if the window remains bookable and has capacity.
- Paid orders with no available window enter payment_received_delivery_review with committed stock retained. They cannot proceed through normal fulfilment.
- Staff can resolve that state by selecting an available window for the same method after agreeing it with the buyer. The action records staff identity, old/new window, note and time; it does not change payment/invoice or automatically email the customer.
- Existing booked windows cannot have their dates, cutoff or delivery method edited, even after a hold is released. Capacity cannot be reduced below current confirmed plus unexpired held bookings. Version checks reject stale admin saves.
- The order stores delivery-time snapshots, shown in the private order page, admin operations view and receipt. Unconfirmed windows are explicitly labelled.
- Calendar policies restrict access to admin/super_admin. Customer order pages retain existing signature/ownership protections.
- No external carrier, SMTP or payment call runs within the booking transaction.

## Verification

- Relevant commerce regression suite: 181 tests / 640 assertions passed.
- Delivery suite repeated after confirmation-banner changes: 18 tests / 91 assertions passed.
- Checkout quote and window-selection JavaScript: 7 tests passed.
- Production asset build passed; existing ~501 kB JavaScript chunk warning remains.
- Isolated browser: admin login, calendar table, South African time labels, edit form and save checked. Completed a zero-value test checkout through the real storefront UI; its confirmation showed the correct booked window and invoice link. Test data stayed in a separate local SQLite database and mail used the array transport.
- SQLite regression coverage exercises contention outcomes sequentially, not a concurrent MySQL load test.
- Deployment uses a runtime-file whitelist, excluding test data, helper scripts, local environment files and unrelated working-tree edits.
- A broad formatter invocation also normalized formatting in pre-existing modified local PHP files; unrelated files are excluded from the release.

Time-picker implementation follows [Filament's timezone documentation](https://filamentphp.com/docs/5.x/forms/date-time-picker): the form displays South African time and stores application time (UTC).

## Remaining merchant and engineering work

- Merchant must approve delivery capacity/cutoffs and publish dates before switching scheduling on.
- DSV API product documentation, account credentials and fresh-flower service suitability remain unverified; this calendar does not create DSV shipments.
- iKhokha/Stripe live merchant configuration and end-to-end real-payment acceptance remain outstanding.
- Automated refunds/returns, confirmed-booking rescheduling/cancellation and customer notifications for schedule changes are not implemented by this release.
- Stock-review recovery, uncertain payment reconciliation, asynchronous supplier dispatch and the wider commerce gaps documented in earlier audits remain separate work.
- No blanket “bug-free” or full production-readiness claim is made.

## Release record

Backup: /var/backups/flowershop/delivery-20260827-1 (database and code, gzip integrity verified).
Migration: 2026_08_27_000014_add_delivery_scheduling.
Package SHA256: e6c6504f6b834ff71b7a24c05c0b0dcaf4c0229d0f96c3606873bd97e763aacf.
Asset manifest SHA256: 7d360ec9b3330b7c7b8cc0161ee4b32441a72df4f5f8123aa46ef60837e078f5.
Deployment completed. Migration 000014 applied; configuration/routes/views cached successfully; nginx, PHP-FPM and the checkout recovery timer are active. Home, products, admin login and the new CSS asset return HTTP 200; unauthenticated calendar access redirects (302). Production policy and route discovery passed. Scheduled methods, slots, bookings and delivery-review orders were all zero immediately after deployment. No live customer/order/payment data was created for testing.

Rollback should restore only the whitelisted previous runtime files and prior asset manifest from the backup. Leave the additive tables/columns in place; do not drop booking data or restore a whole database over newer orders. Before rollback, disable newly enabled scheduled methods only with merchant coordination, because old code does not enforce slots. The initial release has all methods disabled for scheduling.
