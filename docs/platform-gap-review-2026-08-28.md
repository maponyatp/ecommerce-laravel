# Platform gap review — inventory operations and CMS publishing

## Comparison and scope

This is a single-store application with a South African/ZAR checkout, not a complete replacement for Shopify or the WooCommerce ecosystem. A model or configuration screen is not evidence of a finished merchant workflow. This review builds on the earlier checkout, purchasable-variant, email/invoice and admin-usability releases.

Shopify documents stock adjustments with counts, additions/removals, reasons and adjustment history. These are useful baseline operational capabilities, especially for a flower shop handling wastage. Source: [Shopify inventory adjustments](https://help.shopify.com/en/manual/products/inventory/adjusting-inventory/adjusting-inventory-quantities).

WooCommerce documents order management, manual orders, order notes, refunds and customer payment links. Existing private notes are already implemented here; manual orders and provider-confirmed refunds must not be marked complete merely because notes or return records exist. Source: [WooCommerce single-order management](https://woocommerce.com/document/managing-orders/view-edit-or-add-an-order/).

## Gap matrix

| Workflow | Current evidence | Remaining gap |
| --- | --- | --- |
| Catalogue / variants | Purchasable physical fixed-price options, separate price/stock/weight, publication history | Digital options, simple-product SKU administration, combination generation, validated bulk imports, backorders |
| Daily stock operations | This release adds central stock-unit list, checkout holds, receiving/wastage/counting, private history and exports | Multi-location inventory, supplier purchasing, batch/expiry tracking, cost accounting, reconciliation of inventory lost while already reserved |
| Checkout / payments | Server-owned pricing, reservations, recovery, iKhokha implementation | Merchant credentials, real provider acceptance and settlement evidence, production-like concurrent MySQL/load tests |
| Orders / returns | Queues, private notes, guarded fulfilment, receipts, returns intake | Manual/draft orders, partial shipments, supported full/partial refunds and credit notes; return intake does not move money or stock |
| Delivery | Local methods, scheduling, capacity and dispatch references | Account-specific DSV rates, bookings, labels, tracking, retries and flower suitability acceptance |
| Invoices / email | Central branding, frozen seller/buyer invoice data, separate billing identity, conditional tax-invoice title | Real merchant details/logo, reviewed VAT treatment, credit documents, accounting exports and email-delivery acceptance |
| CMS / themes | Admin-managed branding, menus and homepage settings; CMS pages now have separate drafts, private preview, publish/unpublish and revision restore | Preview/revisions for homepage settings and menus, reusable block templates, URL redirects, full responsive/accessibility acceptance |
| Commercial operations | Role checks, launch checklist, scoped release backups | Verified MFA, credential rotation, restoration drill, monitoring/alerts, repeatable release/upgrade process |
| International / advanced models | Current store scope is explicit | Multi-currency/tax/localization, international fulfilment, subscriptions, bookings, marketplaces and channel synchronization |

## Gaps closed in this release

1. **Stock overwrite and reservation bypass:** legacy product actions used stale quantities; the adjustment endpoint did not check active holds. One locked service now verifies the expected on-hand count, validates bounds, protects active holds and records the audit in the same transaction.
2. **Metadata saves overwriting stock:** existing product forms no longer edit stock. The catalogue update API rejects `inventory_count` with instructions to use the stock workflow. Initial stock on new-product creation is unchanged. Existing stock integrations using catalogue PATCH must migrate to the adjustment workflow.
3. **Variant visibility:** Catalogue → Inventory shows one stock row per simple product or published option, not the unused parent quantity. It includes paused options separately and excludes unpublished drafts. Low-stock overview and its destination share the same reservation-aware query.
4. **Unusable fragmented stock tools:** receiving, expired/wilted flowers, damaged stock and stock counts have one UI with clear modes and staff explanations. Option stock updates increment its live version so an older publication form cannot overwrite them.
5. **Inventory report failures and privacy:** the selected-product export no longer depends on an absent CSV package, assumes every product has a category, or writes a predictable public file. Exports stream privately, include options and holds, neutralize formula-leading text and stop at 10,000 rows with a request to narrow the view. Simple products have no stored SKU column and are honestly exported without one.
6. **Option navigation:** browser inspection caught and corrected double-escaped query parameters in the new option links. A regression checks the rendered link encoding.
7. **Super-admin catalogue access:** production verification found both existing super-admin accounts lacked the lower-case product permissions used by resource policies. An explicit, additive repair command grants only `view_any_product`, `view_product`, `create_product` and `update_product` to the existing global `super_admin` role. It defaults to preview, requires `--apply` to change permissions, never creates/promotes users or replaces other permissions, and leaves hard policy denials intact. New tests use the real policies without a test-only authorization override.

## Merchant instructions

The subsequent CMS page lifecycle release is documented in [CMS publishing — 28 August 2026](cms-publishing-2026-08-28.md), including deployment, verification and remaining theme-management limits.

- Open **Catalogue → Inventory** or use **Manage stock** beside a product.
- Search by name, option title or variant SKU; use Low stock, Out of stock or Paused options.
- Open a stock unit. **On hand** includes units held at checkout; **Available after holds** is not a promise that a paused option can be purchased.
- Choose **Adjust stock**: use Adjust by for positive/negative movements, Set to for a counted total. Supply a reason and optional supplier/reference note.
- If stock changed while the form was open, reopen it. Repeating an already-applied nonzero adjustment with the stale quantity is rejected.
- If a count falls below active holds, resolve the affected checkout situation first. This action will not silently cancel orders or invent stock. A dedicated shortage-resolution workflow remains future work.
- Use **Export this view** for a private operational CSV. It is a snapshot, not an accounting valuation, backup or import template.

## Limits and release checks

No refund, restock of returns, courier booking, email, stock publication or customer-order change is performed automatically. The adjustment history is application-level evidence, not a tamper-proof accounting ledger. Initial and legacy quantities are not backfilled with invented staff history. Existing secondary analytics widgets are not replaced by this inventory workspace and need a separate full reporting audit.

Testing uses an isolated SQLite database and synthetic catalogue/order records. Production release and final regression results are recorded below. No assertion of full platform parity, worldwide readiness or zero bugs is made.

### Verification and release record

- Broad inventory/checkout/catalogue/admin regression: **138 tests, 919 assertions passed**. Final inventory-only regression after copy/attribute refinements: **14 tests, 77 assertions passed**; this overlaps the broad run.
- Browser skill used for desktop list, option deep-link, stock detail and adjustment verification. A synthetic expired-flower adjustment changed the selected option from 4 to 3 and appeared in its history with the staff name. No live merchant stock was used. No mobile certification is claimed.
- Scoped Pint passed. No compiled assets or database migrations were required.
- Eleven-file inventory release deployed with matching before/after hashes and verified SQL/code backup at `/var/backups/flowershop/inventory-operations-20260828-1`. Archive SHA-256: `8f06dd68c3d3bceed64bd3ef17e84438c869d66e3f751bd6128e974d9d3d154d`.
- The first two read-only production diagnostics logged an authorization exception while identifying the pre-existing super-admin permission gap. The first assumption that panel bootstrap was missing proved insufficient; a per-account permission check established the actual cause. The diagnostic now exits nonzero explicitly on failure instead of relying on Laravel's standalone exception handler. These were diagnostic failures, not storefront HTTP failures.
- A proposed global super-admin gate was rejected before deployment: security tests correctly detected that it would bypass the intentional ban on support-case deletion. The original configuration remains in place; the final repair uses four explicit catalogue permissions instead. Never deploy the discarded global override.
- Final explicit-permission/security regression: **48 tests, 307 assertions passed**, including the real super-admin permission repair, ordinary-admin/customer restrictions, stock operations, chat access and support-case deletion protection. It overlaps earlier suites; do not add these totals as unique coverage.
- The follow-up command `app/Console/Commands/RepairCatalogueAccess.php` was deployed separately (SHA-256 `8d7bf1322697999781c1a4fcfcd164e81b3a062c50204c86d58d7d5f84a14013`). Preview reported exactly four missing catalogue permissions, then apply added them only to existing global super-admin role #1. No user was promoted, no other role was changed, and existing grants were retained. The verified pre-release SQL backup preserves the previous role configuration.
- Both existing production super-admin accounts now pass inventory access and product view/update permission checks. The original Shield configuration hash still matches `8322c22b55ade55d3a770e694dcff614939ef88c8668de2188cdfafc8ba11dfb`; no global policy override was deployed.
- Production MySQL inventory queries, including union rows and reservation joins, run successfully. Products/orders/invoices remain at zero, failed jobs are zero and debug remains disabled. The setup guide remains 0/6 with real contact details as the next step; permission repair does not complete merchant setup.
- Final HTTP smoke: health/home/products/cart/admin-login all 200; guest inventory/products administration both redirect to login (302). All eleven release hashes still match and nginx/PHP-FPM/recovery timer are active. Error count is 1247, exactly two more than baseline; both are the documented pre-repair standalone diagnostic exceptions. No further errors were observed in final smoke checks. Discarded override scripts were removed, and the isolated local server/tab were stopped.
