# Customer order status release — 29 August 2026

## Scope and outcome

Released five reviewed presentation files to `/var/www/shop.maponya-tech.com`.
The iKhokha return message, checkout confirmation, delivery-window summary and
order receipt now distinguish payment receipt from stock and delivery readiness.

- Paid stock/delivery exceptions no longer display unconditional order confirmation.
- Cancelled orders do not imply that cancelling returns money or cancels an external payment.
- Refunded states display **Refund recorded**, not **Payment pending**. This is a
  description of the saved state, not proof of bank settlement.
- A browser return query saying `cancelled` cannot establish payment cancellation.
- Expired stock holds are not displayed as active while awaiting recovery processing.
- Scheduled deliveries require a matching confirmed booking and an order ready for fulfilment
  before customer-facing pages or receipts describe the booking as confirmed.
- Unknown paid fulfilment states and missing inventory commitments require review.
- Uncertain/failed payment messages advise checking with the store before paying again.

The shared presenter is `app/Support/CustomerOrderStatus.php`. It is read-only.
No payment processing, refunds, receipt dispatch rules, gateway requests, migrations,
products, merchant settings or customer records were changed by this release.

## Verification

- Initial new regression checks reproduced contradictory wording. The first broader
  run had 14 failures confined to the new tests: database-default comparison and
  delivery-date fixture issues. Corrected those fixtures, not the application to
  satisfy an incorrect expectation.
- **145 existing PHP regressions / 882 assertions passed** across checkout, recovery,
  commerce, delivery scheduling, email branding, invoices, digital fulfilment,
  flower gifting, iKhokha webhook validation and refund workflow.
- **17 final new PHP tests / 193 assertions passed**, including saved-state invariance,
  signed return/confirmation routes, receipt wording, stale reservations and eight
  scheduled-booking combinations. No notifications or HTTP requests were sent.
- **11 JavaScript tests passed** for checkout quoting, delivery-window controls and anchors.
- These are scoped results across two runs, not a claim that the entire repository
  suite or browser/mobile accessibility testing was rerun.

Tests used isolated in-memory SQLite, array email/cache/session transports and
HTTP fakes. The production check used MySQL in a read-only transaction and rendered
11 synthetic, unsaved order states and receipt messages; it sent no emails and
attempted no payments.

## Production evidence

Post-release audit: **2026-08-28 23:17:03 UTC / 29 August 01:17:03 SAST**.

- Homepage, products, cart, health and admin login: HTTP 200.
- Empty-cart checkout and unauthenticated admin readiness page: expected HTTP 302.
- 139 InnoDB tables; 200 foreign-key relationships checked; no orphaned relationships.
- 623 PHP files parsed; no syntax errors or missing registered route-handler methods.
- Debug disabled; zero failed jobs. Error count unchanged at 1,251, most recent
  historical error at 28 August 18:14:53 UTC; no new logged errors during verification.
- Products, shipping methods, orders, invoices and payment transactions remained zero.
- Private environment, built-asset manifest, Composer files, payment service and
  receipt-dispatch service hashes remained unchanged.

## Release boundaries and recovery

Compared each of the four pre-existing live files with the review-branch baseline
before release; all matched after newline normalization. Verified exact live hashes
again immediately before deployment. The new presenter did not previously exist.

Backup: `/var/backups/flowershop/customer-status-20260829-1`.

Artifact SHA-256:
`c27ef6df13d7ed65362754934e8279e8b8d0f90a4774e435175a1b915c0a6958`.

Deployment included a short maintenance window, code backup, scoped extraction,
file hashes, PHP syntax checks, view compilation, read-only rendering, worker restart
signal, PHP-FPM reload and HTTP checks. Rollback was not needed. Recovery restores
only the four backed-up files and moves the new presenter into the private backup;
it must not restore an old database over customer activity.

Only the Maponya review branch is updated. GitHub `main` and unfinished theme,
refund, firewall and other module work are not promoted by this release.

## Remaining launch requirements

This closes a customer-communication gap; it does **not** certify the entire store
as production-ready or establish parity with hosted commerce platforms.

The live readiness report still flags contact email, seller details, VAT decision,
catalogue and payment configuration. No products or delivery methods are configured.
Merchant-supplied product stock, pricing, delivery coverage/rates/windows and live
gateway credentials are required before supervised real-order acceptance testing.

DSV booking/label/tracking integration and checkout implementations for the additional
South African payment providers remain outside this release. Live settlement/refund
acceptance, restore drills, concurrent MySQL checkout/load testing, monitoring and
broader security/browser/accessibility verification remain outstanding.
