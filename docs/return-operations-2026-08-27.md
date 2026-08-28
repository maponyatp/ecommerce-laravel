# Staff return operations — 27 August 2026

## Scope

Adds physical-return intake, approval/rejection, partial item receipts, condition/handling decisions and audited completion. This is **not a payment refund module**. No money, stock, digital access, credit notes, replacements, courier bookings or customer emails are changed by these controls.

## Staff workflow

Open Admin → Commerce → Returns, enter an order number, then choose **Record return request**. The order editor also links to Returns.

1. Record a reason, private intake details, handling method and purchased physical items/quantities.
2. Review and approve, reject or cancel the request. Approval must be saved before recording receipt.
3. Record cumulative received quantities and a condition/handling decision for each received line. Partial receipts stay approved until all quantities are received.
4. Mark fully received, then complete handling. A staff-approved no-physical-return-required case may be completed without receipt.

Completion does not mean the customer was refunded. Returned flowers are never automatically added back to saleable inventory. Collection is manual; selecting it does not book DSV.

## Safeguards

- Requires an administrator/super-administrator with order view/update permissions. Read-only order staff cannot mutate returns; revoked permissions are rechecked on save.
- Account and guest orders supported. Customer identity is taken from the order, not submitted form data. Customer-account deletion retains the return/history and clears the user reference.
- Intake requires paid/refunded, inventory-committed, dispatched/delivered orders. Cancelled or payment/stock/delivery-review orders are rejected. Digital and unknown legacy product types require separate review.
- Quantities cannot exceed the purchased quantity after earlier active/completed requests are counted. Rejected/cancelled requests release that allocation.
- A request UUID plus normalized fingerprint prevents a normal repeated submission from creating duplicate returns. Changed payloads cannot reuse the UUID.
- Order/return locks, current-revision checks, transactional audit records, parent-bound identifiers and locked Livewire references protect the covered workflows. These tests do not establish cross-connection MySQL concurrency under load.
- Received quantities cannot decrease or exceed requested quantities. Received lines need condition and disposition. Returns with received items cannot be cancelled. Completed/rejected/cancelled records are locked.
- Notes are staff-only and escaped; pages use private-response middleware. Audit versions retain before/after item states and actor references.
- Legacy returns without a revision stay read-only pending reconciliation. Missing historical receipt information is not inferred.
- The legacy Refund::process() shortcut now rejects pending processing: bookkeeping alone cannot establish that gateway money moved. Legacy return approval/receipt helpers likewise reject bypassing the guarded service.

Migration 000021 adds return revisions, request deduplication, item receipt fields and an audit table. Automatic down-migration is deliberately blocked to avoid discarding history. Rollback requires a reviewed backup/reconciliation plan; never blindly restore over new customer activity.

## Verification

Selected regression suite: **115 tests / 610 assertions passed**, including 19 new return-operation tests and refund/return model, shop-operation, order-support, cart, checkout and commerce regressions. Tests exercise actual Livewire intake/approval actions, authorization, invalid/stale saves, audit rollback, partial receipts, over-return rejection and customer-deletion retention.

An existing refund-item test fixture lacked the schema-required reason; the fixture was corrected, not the database constraint. Scoped formatting/PHP syntax checks and the production asset build passed. Build retains the existing large-JavaScript-chunk warning. Isolated SQLite migration succeeded. Browser webview attachment failed; visual/mobile/accessibility verification is incomplete. No live customer return/refund/order/email was generated for testing.

## Release

Scoped archive: nine runtime/migration/view files plus public/build; excludes tests, fixtures, docs and secrets. Before/after source hashes protect against overwriting a changed production baseline.

- Backup: /var/backups/flowershop/return-operations-20260827-1
- Archive SHA-256: 3c8eb93cc8be5f05a129ac5f6594752c2d17fae9c5014334277c451ef874f657
- Manifest SHA-256: 858a7c3a33bf7855cbc9365be87e35a2f06c9f64a60d50421701d0da4d224392
- Deployment status: deployed successfully after verified database/code backup.

Migration 000021 completed on production MySQL. Configuration, routes and views were cached, queue restart signalled and PHP-FPM reloaded. All nine runtime/migration/view hashes and the build manifest matched the release. Read-only checks confirmed private returns routing, locked record identifiers, the new schema, customer-reference null-on-delete behavior and disabled unverified refund processing. Production return/refund counts remained zero; no live records were created for testing.

HTTP 200: health, home, products, comparison, cart, admin login and the new admin stylesheet. Guest /admin/returns returned 302; the same unauthenticated request accepting JSON returned 401. Application is out of maintenance mode; nginx, PHP-FPM and checkout recovery timer are active. Both payment-configuration checks remain false.

## Remaining blockers

- Production iKhokha and Stripe configuration is missing; paid checkout and merchant-approved real settlement/refund verification remain blocked.
- Gateway-confirmed full/partial refunds, credit notes and payment-cancellation reconciliation are not implemented by this release.
- DSV account-specific booking/labels/tracking need the correct API documentation and merchant credentials.
- Variants remain planning drafts, not purchasable stock-backed options.
- International selling remains ZAR/South African delivery only.
- Full security/team/supplier-path review, load/concurrency testing, mailbox delivery monitoring, a restore drill and complete browser/mobile checks remain necessary. This is not a claim of Shopify parity or zero defects.
