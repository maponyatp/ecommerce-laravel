# Checkout reservations and receipt recovery — 27 August 2026

## Delivered scope

This release hardens the existing flower-shop checkout. It does not complete every commerce feature or implement payment-provider reconciliation/refunds.

- Physical/digital catalogue stock is held for 30 minutes before a new payment request. On-hand stock is not deducted by a hold; available stock subtracts live holds.
- Product rows are locked in product-ID order. The final reservation validates current availability and item prices. Limited coupons also count paid orders and active checkout holds; coupon value/minimum/expiry/usage are rechecked under the coupon lock.
- The order, items and reservations are created transactionally. A server-owned checkout identity, request/cart fingerprint, database unique key and session locking protect repeated submissions from creating another order/payment request for the same checkout. Stripe requests include an order-specific idempotency key. iKhokha link creation no longer automatically retries an ambiguous POST.
- Confirmed iKhokha, Stripe and free orders use a shared settlement path for all-or-nothing stock commitment, one invoice, digital entitlements and receipt-outbox insertion. Replays do not deduct twice or reopen a completed/refunded order.
- Verified payment failure releases holds, without increasing on-hand inventory. Expiry releases a local hold but does not mark a gateway payment failed. Late settlement cannot consume another buyer's current stock reservation.
- A late payment without available stock is recorded as paid and flagged for stock review. Payment of a cancelled order does not reactivate fulfilment. These cases need staff resolution; no refund is fabricated.
- Each newly settled order gets one persistent receipt record. Sending uses the mail transport synchronously after the settlement transaction. An email exception does not undo the payment, invoice or stock.
- Failed delivery retains a sanitized error and exponential retry time. A ten-minute claim lease prevents normal competing sends and allows recovery after worker interruption. After ten failures, staff can queue another attempt from the order's **Retry failed receipt** action.
- Admin order details show reservation status/expiry and receipt status/attempt count. The customer confirmation page warns about expired holds, unconfirmed payments and paid stock-review cases.
- SMTP connection timeout is now 30 seconds. Existing credentials are unchanged.

## Operational recovery

`php artisan commerce:recover-checkouts --limit=25` releases expired holds and retries due receipts. The application scheduler also registers this command.

The production release installs a dedicated `flowershop-checkout-recovery.timer` / `.service`, running as `www-data`, one minute after the preceding run ends. The one-shot service has a bounded runtime and read-only system protection with writes limited to application storage/cache. Per-order database claims protect against a simultaneous invocation from an existing Laravel scheduler.

The recovery process acts only on reservation/outbox records created by the new flow. It does not bulk resend historical receipts, create customer orders, call refund APIs, or book shipments.

## Important limits

1. **A stock hold is not gateway-link cancellation.** iKhokha paylinks can still be paid after local expiry. Such payments may require stock review/refund. Strict payment-window enforcement needs the merchant's supported gateway expiry/cancellation/reconciliation API.
2. **Ambiguous payments need review.** Lost provider responses and Stripe settlement interruptions are not automatically reconciled through status APIs. Orders with ambiguous initiation are kept for review rather than called failed or automatically recharged. A lost iKhokha response may also lack the paylink reference required for callback matching.
3. **Coupon guarantees are limited to active checkout holds.** A late external payment can settle after its coupon hold expires; the already-paid amount is not retrospectively changed. Provider-side expiry/reconciliation is needed to guarantee a global promotional cap through arbitrarily late payments.
4. **Email is at-least-once, not exactly-once.** If SMTP accepts a message and the process fails before recording acceptance, recovery may send a duplicate. “Sent” means the transport returned successfully, not inbox delivery. Historical confirmation timestamps may mean queued rather than actually sent and are not automatically rewritten.
5. Tests use SQLite and simulated gateway/mail behaviour. Lock ordering and database uniqueness are implemented, but this is not a MySQL concurrency/load test or a live settlement/SMTP-deliverability test.
6. Other legacy purchase/stock mutation paths, supplier dispatch, partial refunds/restocking, inventory administration concurrency, and complete variant/customer workflows still require work.
7. Browser/mobile visual QA remains incomplete from earlier releases; this pass uses HTTP/Livewire and automated regression checks, not a successful real-browser run.

## Verification

- Existing regression run plus reservation tests: 160 tests / 540 assertions passed.
- Three additional edge-case tests passed / 9 assertions: all-or-nothing stock after an admin change, changed coupon values and a second settlement after completion.
- Combined: **163 backend tests / 549 assertions**, plus **4 checkout JavaScript tests**.
- New tests cover the last bouquet, active holds, verified failure, expiry, late payment with/without available stock, cancellation, limited coupons, repeated checkout, ambiguous gateway initiation, free settlement, receipt failure/backoff/claim recovery/retry limits and suppression.
- Asset build, local migration 000013, view compilation and schedule registration passed. Existing JavaScript chunk-size warning remains.

## Production release

Deployed to `https://shop.maponya-tech.com` with verified database/code backup `/var/backups/flowershop/recovery-20260827-1`.

- Release archive SHA-256: `663ef583a32d7f75087b9dd9f2d410d08cf9c47e924d58d0f985f8e215ce2ee8`.
- Migration 000013 ran successfully. Configuration, routes and views were rebuilt, queue restart signalled, PHP-FPM reloaded and the site returned to live mode.
- The recovery timer is enabled and active; the service returned `Result=success` / exit 0. Its initial runs found zero expired holds and zero due receipts. No historical receipt backfill or synthetic production order was performed.
- The deployed asset manifest matches the validated local build. Health, home, catalogue, admin login and CSS checks returned 200. Guest admin/order/delivery and empty-cart checkout requests redirected (302). Quote POST without CSRF returned 419; unsigned webhook returned 403.
- To pause only this recovery worker, an operator can stop/disable `flowershop-checkout-recovery.timer`; if a separate Laravel scheduler is installed, pause its recovery entry too. Existing holds still expire for availability calculations, but receipt retries will wait. No live payment/refund/shipment was submitted during verification.

## References

- [Laravel database locking](https://laravel.com/framework/docs/13.x/queries#pessimistic-locking): transaction-bound row locks.
- [Laravel notification delivery API](https://api.laravel.com/docs/13.x/Illuminate/Notifications/NotificationSender.html): explicit immediate delivery versus queuing.

## Remaining priorities

Payment reconciliation/refunds and credit notes; flower delivery dates/capacity/cutoffs; DSV; unified customer management; variants; browser/mobile/security/load/restore testing. See `shop-management-gap-audit-2026-08-27.md` for the broader feature matrix.
