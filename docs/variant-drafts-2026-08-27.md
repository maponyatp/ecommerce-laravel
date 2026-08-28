# Product variant drafts — 27 August 2026

## Scope

Admin → Commerce → Variant drafts supports planning bouquet sizes/colours and proposed SKUs/prices for physical, fixed-price products. This is **not purchasable variant support**. Publishing, variant selection in the storefront, per-variant inventory/reservations and variant order snapshots remain open.

Drafts use separate tables; they do not update products, legacy product_variants, inventory, carts or orders. Proposed SKUs are unique among drafts only (including archives); future publishing must check live SKU conflicts.

## Controls

- Product view-any/view permissions plus admin/super-admin role are required to browse. Product update permission is checked on every save.
- Parent and draft targets are locked in Livewire and checked together server-side.
- Version checks reject stale edits; unchanged saves do not generate revisions.
- Draft save and before/after audit are atomic. Actor, parent, currency and revision are server-owned.
- Up to three named options, bounded names/values, strict SKU/price validation and a 100-draft-per-product limit (including archives).
- Search by product name or draft title/SKU, active/archived/all filters, paginated catalogue and audit history.
- Archive/restore preserves history and the proposed SKU. There is no hard-delete action.
- Currency changes cannot silently relabel existing proposed prices.
- Private response middleware and escaped draft/history values. Customer accounts cannot access the page.

The migration restricts hard deletion of a parent with drafts and hard deletion of a draft with revisions to preserve history. Normal product soft deletion remains available. Removing draft data or rolling back migration 000019 requires an explicit retention/backup decision.

## Verification

- 56 tests / 367 assertions passed: ProductVariantDraftTest (15 new tests), CartIntegrityTest, CheckoutControllerTest and CommerceGapRegressionTest.
- Existing ProductVariantModelTest and ProductOptionModelTest: 12 tests / 21 assertions passed.
- Total selected coverage: **68 tests / 388 assertions**. This is not a full-suite rerun or a no-bugs guarantee.
- Actual Filament/Livewire create and edit actions, option persistence, visible validation, archive, revoked permissions, stale edits, wrong-parent references, locked state, audit rollback, currency changes and unchanged live cart pricing are covered.
- Production asset build passed; the existing large JavaScript chunk warning remains.
- After the selected suite, an empty-search normalization fix passed its focused catalogue test (1 test / 13 assertions). It does not change styles or build inputs.
- Local isolated SQLite migration passed. Local browser preview failed to attach twice, so visual/mobile verification is incomplete. SQLite tests do not establish MySQL write concurrency under load.
- No real customer order, payment, shipment or email was created for testing.

## Release

Release preparation: scoped six new runtime files plus public/build. No credentials, fixtures, tests, docs or unrelated working-tree changes are included.

Backup: /var/backups/flowershop/variant-drafts-20260827-1 (database and code archives integrity-checked).

Archive SHA-256: d06a9d494f3213180a9b1f0b8c85c2d2f2b57d59c477c0b2a652c9f77f1d9f6b

Supplemental page-only archive SHA-256: c0d1c4149a9326bdf0a792325c6e5c1bd6f67fcbdccdb95c066fdddea5203e60. Applied after the main archive in the same maintenance window; contains the tested empty-search fix.

Build manifest SHA-256: fcacc8af8832ca61431a8a4e461562c2b6b88fecadcb313c32d29b4946c4913e

Deployed successfully to https://shop.maponya-tech.com/admin/product-variant-drafts.

- Migration 000019 applied in production; configuration, routes and views rebuilt, queue restart requested and PHP-FPM reloaded.
- All six final source hashes and the build manifest matched the local tested files.
- Read-only production checks passed for both tables, recorded migration, private route middleware, locked product/draft targets and MySQL filtered pagination.
- Zero production drafts/revisions existed at verification; no synthetic production record was added.
- HTTP 200: /health, homepage, /products, /cart, /admin/login and the new admin stylesheet.
- Guest /admin/product-variant-drafts redirected to /admin/login (302); empty-cart /checkout also returned 302.
- An initial probe to /up returned 404 because this application uses /health; the actual health route returned 200.
- Application is out of maintenance mode; nginx, PHP-FPM and checkout-recovery timer are active.

## Remaining work

The immediate next variant milestone is a reviewed publish/activation workflow with storefront selection, authoritative variant prices, variant stock holds, immutable order snapshots and payment/recovery regression coverage. No claim of Shopify parity, international readiness or overall production certification is made by this release.
