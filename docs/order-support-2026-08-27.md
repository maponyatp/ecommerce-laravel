# Order support release — 27 August 2026

## What is now implemented

- Customers can open order-linked support cases from the private order confirmation or account order page.
- Categories cover damaged/poor-quality flowers, missing deliveries, wrong/missing items, delivery questions, payment/refund enquiries and other order concerns.
- Optional item and affected quantity are validated against that order's actual line items.
- One active support case per order, repeat-submission tokens and row locks protect against duplicate cases/replies.
- Customer replies and staff public responses are stored as append-only messages; private staff notes are excluded from customer rendering.
- Admin → Commerce → Order support lists cases, buyer/order, category, status and last activity, with an active-case badge.
- States: Open → Under review / Awaiting your reply / Closed. A customer reply changes Awaiting reply back to Under review.
- Closing or requesting a reply requires a customer-visible explanation. Closed cases are read-only; a customer can open a new case.
- Stale staff saves are rejected. Customer replies cannot be overwritten by an outdated staff form.
- Case status changes record actor and time. Order operations link to the associated support cases.

This is an after-sales support workflow, not automated returns/refunds. It does not approve replacements, create return labels, move money, generate credit notes, change invoices, restock bouquets, or revoke digital downloads. Case closure is explicitly distinguished from a completed refund throughout the UI.

## Access and privacy

Guests use a short-lived signed link generated only from an authorized private order page. Account owners can use their authenticated order ownership; claiming a guest purchase by email requires email verification. The signed order-support link is a bearer capability: anyone it is deliberately shared with can read and reply, so the UI says to keep it private. Expired links can be renewed by revisiting the original protected order/receipt page.

POSTs require CSRF protection, are limited to six per minute, and use session/order locking. Case IDs and item IDs cannot cross order boundaries. User-supplied status, author, internal-note and order ownership fields are not accepted. Messages are escaped on display. Pages are private/no-store with no-referrer headers. History links preserve valid signatures across pagination.

No attachments are accepted in this release. Sensitive payment data/passwords are explicitly discouraged in support messages. There is no deletion control; orders with support history are protected by foreign keys.

## Admin validation defect fixed

A regression test demonstrated that service errors were keyed to `public_message` while the admin form expects `data.public_message`. Added a shared mapping helper and applied it to support saves, delivery-window create/edit, order fulfilment saves and delivery-resolution/private-note actions. Service validation failures now appear against the correct visible field. Tests cover closure explanation, capacity reduction and unpaid fulfilment.

## Verification

- Relevant commerce suite: **203 tests / 762 assertions passed**.
- JavaScript checkout quote and delivery-window tests: **7 passed**.
- Build: passed; existing ~501 kB JavaScript bundle warning remains.
- Covers signed/expired/tampered access, verified guest ownership, item/quantity scoping, one-active-case and duplicate reply protection, private-note exclusion, HTML escaping, CSRF rejection, rate limiting, no refund/restock side effects, staff stale-save protection, actual Livewire list/save operations and field-level validation errors.
- Browser preview failed to attach twice in this pass. No visual/mobile support-page verification is claimed. Previous delivery-calendar release had a successful isolated browser checkout.
- Tests use SQLite, not a concurrent production MySQL load test.

## Operational requirements and outstanding work

Staff must monitor Order support and respond there. Automated support email alerts, case assignment/SLA escalation, image evidence and exhaustive transcript export are not yet implemented. Customer and staff screens state that replies appear on the private page and no support email is sent. Urgent enquiries link to the shop's contact page.

The existing legacy Refund::process method does not verify gateway money movement and remains outside this new workflow; no “refund completed” button exposes it. Verified payment refunds, credit notes, replacement fulfilment, cancellation/restock reconciliation, merchant policies and DSV credentials/API integration remain separate work. This release does not establish full Shopify feature parity or blanket production readiness.

## Deployment

Backup: /var/backups/flowershop/support-20260827-1, database and code integrity verified.
Migration: 2026_08_27_000015_add_order_support_cases.
Package SHA256: 3271a8c5f484735d67a3f407b71274ef46c2eb1aeec7038bcf08b7896f986643.
Build manifest SHA256: 14cacce5b168fc1d764b0a27cba14cb5a89dc492e7899bde1d2ad4f4f3f62b7a.

Only whitelisted runtime files and built assets are released; fixtures, local test databases, helpers, .env files and unrelated local edits are excluded. No live test support cases, orders, emails, refunds or shipments are created.

Production verification completed: migration 000015 applied, route/policy discovery passed, configuration/routes/views rebuilt, nginx/PHP-FPM/recovery timer active. Health, home, catalogue, admin login and both new CSS assets returned 200; unauthenticated admin inbox access returned 302; a support POST without CSRF returned 419. No cases or messages existed immediately after deployment and scheduling remained off. The production asset manifest matched the release.

For rollback, restore only the previous whitelisted runtime code and build manifest from backup; keep additive support tables and any customer messages. Do not restore a whole database over newer orders or discard support history.
