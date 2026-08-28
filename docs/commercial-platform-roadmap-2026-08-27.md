# Commercial platform readiness

## Scope and release decision

The target is a configurable **single store per deployment**, initially for ordinary physical and digital products. This is not a multi-merchant SaaS platform, marketplace, universal subscription engine, regulated-goods platform or booking system. Each additional store model needs its own complete workflows and acceptance evidence.

**Not ready for an unrestricted commercial launch.** A successful code deployment or passing test suite is not a certification of all commerce workflows. Current checkout remains ZAR with South African delivery. Nothing in this milestone changes the flower shop's public branding or authorizes overseas shipping.

## Foundation milestone delivered

- New business/invoice tab in Store design & settings: seller name, address, registration number and tax registration number. Existing homepage and brand data remain unchanged.
- One invoice-issuance service used by payment settlement and the legacy helper, with order locking and repeat-issuance protection.
- New invoices snapshot seller identity/contact details, purchased item descriptions, quantity, prices, totals, currency, customer email and delivery address. Changing the catalogue, order details or store name does not rewrite the issued document.
- Application-level model guards reject edits to issued invoice contents and invoice deletion. These are not database-level protection against privileged raw SQL, cascading deletes, or a tamper-evident accounting ledger.
- Historical invoices are not silently backfilled. Existing order-name snapshots are used; missing names and seller details are identified honestly. Legacy invoices have a visible incomplete-history warning.
- Invoice documents have an independent, print-oriented layout. Delivery addresses are not falsely labelled as billing identity; invoices do not automatically claim to be compliant tax invoices.
- Payment status remains separately labelled as current; snapshot contents remain fixed. This does not implement refunds or credit notes.
- Shared admin copy is business-neutral. The order-attention count now uses the actual `processing` fulfilment state.
- Super-admin-only **Settings → Launch checks** provides six read-only configuration checks and explicit manual acceptance requirements. `commerce:preflight --json` exposes the same secret-free report. Exit 1 means configuration blockers; exit 0 means those checks pass, **not** launch certification.

## Required milestones and acceptance gates

| Priority | Milestone | Completion evidence |
| --- | --- | --- |
| P0 | Merchant activation | Real merchant-approved payment, failed/duplicate/late callback handling, settled funds and receipt confirmed; ambiguous initiation reviewed without duplicate charges |
| P0 | Refunds, cancellations and credit notes | Provider-confirmed full/partial refunds, idempotency and reconciliation, immutable credit documents, reviewed inventory decisions |
| P0 | Operational safety | Enforced/verified admin MFA, least privilege, rotation of credentials previously shared in conversation, monitoring, backup retention and tested restoration |
| P0 | Production-like acceptance | Concurrent MySQL last-item/slot tests; end-to-end physical and digital purchase; mobile/accessibility review; email delivery evidence |
| P1 | Purchasable variants | Physical fixed-price workflow deployed 28 August; see `woocommerce-gap-audit-2026-08-28.md`. Digital variants, bulk workflows, richer variant SEO and production-like concurrency/mobile acceptance remain |
| P1 | Business invoicing | Actual seller details, distinct billing identity/address, reviewed tax treatment, correction/credit workflow and accounting exports |
| P1 | Delivery integrations | Account-specific DSV contract, coverage/product suitability, authenticated rates/bookings/labels/tracking, retries and failed-delivery recovery |
| P1 | Merchant order tools | Phone/manual draft orders and payment requests, safe order editing, partial fulfilment, cancellation and customer notifications |
| P1 | Reusable storefront authoring | Template/block model, preview before publication, revisions/rollback, accessible themes, reusable store-type presets without overwriting merchant content |
| P2 | Inventory operations | Imports with validation/dry run, supplier purchasing/receiving, stocktakes, multi-location reconciliation and wastage where relevant |
| P2 | Customer/marketing | Consent-aware profiles and campaigns, abandoned-checkout recovery distinct from technical checkout recovery, tested gift-card/loyalty accounting |
| P2 | Subscriptions and other store models | Real billing contract, renewal/failure/cancellation lifecycle, product-specific fulfilment and reconciliation; existing placeholder models/services are not sufficient |
| P2 | International and channels | Market-specific payment acceptance, localization, currencies/taxes, fulfilment arrangements, validated feeds and channel stock/order synchronization |
| Ongoing | Maintainability | Repeatable fresh install, documented upgrade/rollback policy, dependency/security updates, reproducible releases, staging and regression CI |

The physical purchasable-variant milestone was deployed on 28 August; see `woocommerce-gap-audit-2026-08-28.md`. Merchant-supplied payment details, a supported refund contract and the other gates remain outstanding. Do not advertise a module as available solely because a model or settings form exists.

## Verification of this milestone

- 108 selected backend tests / 547 assertions passed, no errors/failures/skips. Includes the new invoice and readiness suites plus invoice access, checkout recovery, iKhokha reconciliation, admin routes/settings and fulfilment documents.
- 11 JavaScript tests passed. Scoped Pint and production build passed; existing approximately 501 kB JavaScript bundle warning remains.
- Initial invoice test failure was a fixture comparison before refreshing database defaults; corrected. An initial combined run ended prematurely at the local 128 MB CLI memory setting; the final run completed at 146 MB using a development-only 768 MB limit. Production memory settings were unchanged.
- Browser skill used for desktop invoice and launch-check page visual verification on an isolated SQLite database with synthetic data. Browser timeout interrupted additional mobile verification; the temporary viewport was reset. No complete mobile or print-PDF certification is claimed.
- No live charge, refund, email, shipment, customer order or invoice was created by deployment. No historical invoice backfill.

## Deployment

Deployed 27 August 2026 to the existing server. Backup: `/var/backups/flowershop/commercial-foundation-20260827-1` (SQL and code, gzip integrity checked).

Archive SHA-256: `7bde80f3133743b62fc4bf3c30974e755ad2c23f41e6ce234b12d865fdba8e03`.

Both invoice/settings migrations completed. All 19 source/build file hashes matched; assets exist, routes/views/config rebuilt, nginx/PHP-FPM/recovery timer active. No pending migrations, zero failed jobs, database ping successful and debug disabled. Invoice/snapshot counts remained zero. Live health/home/admin-login/assets returned 200; guest launch-check page redirected to authentication. Error-log count remained 1245.

Live preflight reports two configuration blockers: **seller identity/address** and **payment credentials**. Manual acceptance and the feature milestones above remain outstanding even after those two are supplied. Do not infer full readiness from this short configuration checklist.
