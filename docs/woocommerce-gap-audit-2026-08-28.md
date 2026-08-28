# WooCommerce comparison and closure record

## Verdict

This application is a working single-store commerce foundation, not yet a complete WooCommerce alternative or an unrestricted commercial platform. A defensible percentage cannot be assigned: feature counts would wrongly treat draft models, settings forms and untested integrations as completed workflows. Compare supported merchant journeys instead.

WooCommerce core supports variable products with independently managed prices, inventory and other attributes. This release closes the application's physical-variant purchasing gap; it does not reproduce every variable-product setting or extension. [WooCommerce variable products](https://woocommerce.com/document/variable-product/).

WooCommerce's order-management and refund documentation describe workflows beyond order intake. Refund processing also depends on gateway support; a refund record is not proof of returned money. Those lifecycle gaps remain important here. [Managing orders](https://woocommerce.com/document/managing-orders/), [Refunds](https://woocommerce.com/document/woocommerce-refunds/).

## Current gap map

| Area | Current application | Remaining work |
| --- | --- | --- |
| Catalogue and variants | Simple physical/digital products; this release adds purchasable fixed-price physical options, unique live SKUs, separate price/stock/weight, explicit publication and history | Digital variants, per-option imagery, automated combination generation, backorders, validated bulk variant import/export |
| Checkout and stock | Server-owned prices, quote checks, idempotent checkout, coupon/stock holds, late-payment review, per-option commitment | Production-like concurrent MySQL acceptance and load tests, broader merchant payment testing |
| Payments and refunds | iKhokha checkout/reconciliation implementation; refund execution deliberately disabled | Merchant activation, supported full/partial refund API contract, refund retries/reconciliation, credit notes, real acceptance evidence |
| Orders and fulfilment | Customer/admin order views, receipts, printable invoices and packing slips, returns intake | Manual/draft orders, safe post-order editing, partial fulfilment, integrated cancellation/refund notifications |
| Invoicing | Issued-document snapshots, seller settings, purchased option/SKU retained | Actual seller details, separate billing identity/address, tax review, credit documents and accounting exports |
| Delivery | Configurable local methods, weight pricing, delivery slots and capacity | DSV account-specific integration, rates, bookings, labels, tracking and recovery; storing credentials alone does not implement these |
| CMS and themes | Existing admin-managed branding, navigation, pages and home settings | Reusable block/template authoring, preview/publish/revision/rollback model, complete responsive/accessibility acceptance |
| International stores | Current ZAR/South African checkout and delivery scope | Market-specific currencies, translations, tax and payment acceptance, international fulfilment; no worldwide-selling claim |
| Operations and security | Role gates, tested access controls, launch configuration checks and scoped backups | Verified MFA, rotation of shared credentials, backup restoration drill, monitoring/alerts, release/upgrade policy, dependency reviews |
| Advanced store models | Existing models/settings must not be advertised as working integrations | Subscriptions, bookings, marketplaces, wholesale/loyalty/gift-card accounting and channel synchronization each require their own completed lifecycle |

The WooCommerce ecosystem contains optional paid/free extensions; it is not a single fixed list that any store automatically includes. DSV is an account-specific requirement for this store, not a claim about WooCommerce core.

## Gap closed in this release: purchasable physical variants

- Draft-to-live publication is explicit and authorized. Existing draft/legacy variant records are not automatically activated.
- Each option has its own price, on-hand stock, SKU, packed kilogram weight and sale switch.
- First publication switches the parent to option selection. Parent price/stock are not copied into option inventory, and the parent can no longer be bought without choosing an option.
- The parent remains fixed-price and physical. Published SKU/option identity cannot be reassigned; create a new draft for a different SKU.
- Stock publication detects stale editors, including sales since the form was opened, and cannot reduce stock below active checkout holds.
- Cart keys retain product and variant identity. Server hydration ignores client price, identity metadata, shipping weight and digital flags.
- Separate variants of the same parent can be ordered together. Holds, expiry, settlement and inventory audit logs operate per option; repeated settlement does not deduct again.
- Deactivation blocks new purchases but does not invalidate already-paid pending orders. Late payments cannot take another shopper's held stock.
- Purchased names/options/SKUs flow through order snapshots, confirmation/receipt views, return/support item identification, invoices and packing slips.
- Storefront cards use “Choose options” and the live minimum price. Public sorting/filtering uses that minimum rather than the unrelated parent price. Comparison views do not claim parent-stock availability for options.
- A missing catalogue image now has a shipped SVG fallback.

### Merchant instructions

1. Open **Catalogue → Product variants** (existing URL remains `/admin/product-variant-drafts`), or use **Manage options** on a product.
2. Choose a physical fixed-price product and prepare a draft with SKU, title, option values and price.
3. Open the draft, select **Publish / manage live option**, enter the physical on-hand quantity and packed weight, and enable **Available for sale** when ready.
4. Verify the live section and storefront selector. Draft edits do not alter the live option until explicitly published.
5. To pause sales, turn off the live sale switch. Archiving a draft alone does not unpublish it. Existing orders retain the bought option/price.

Limitations: up to 100 drafts/product and three option names/draft; physical fixed-price variants only; no automatic inventory transfer, backorders or refund/restock workflow. Variant cards currently omit the parent's single-price structured offer rather than publishing incorrect product-offer metadata. Rich variant SEO remains follow-up work.

## Verification and release status

130 selected backend tests / 750 assertions passed, including checkout/payment recovery, variant drafts/publication, cart integrity, invoices, fulfilment documents, admin runtime and commerce regressions. All 11 existing JavaScript tests passed and the production asset build passed. The existing approximately 501 kB JavaScript bundle warning remains.

Final code review additionally corrected the separate search-page price filter and added a regression assertion. Follow-up suites passed: 30 tests / 157 assertions for variants and commerce regressions; 42 tests / 267 assertions for final variant/admin changes. These overlap the main suite and must not be added as unique test coverage. Scoped Pint and diff whitespace checks passed. These test totals are not a certification that every application module is bug-free.

Browser review used isolated synthetic data. Selection reached the cart with the correct option and price. A local development PHP startup timeout occurred during concurrent verification; the isolated preview was restarted with a local-only limit/opcache adjustment. Production PHP settings were not changed.

Desktop product selector, basket and admin publication modal were reviewed. The requested mobile viewport override did not take effect (the DOM still measured 1272 px); it was reset. No mobile verification is claimed. The local preview server was stopped after testing.

No real payment, refund, customer email, shipment or merchant catalogue publication was performed for testing. Real payment/DSV/refund acceptance and the commercial gates above remain open.

## Deployment — 28 August 2026

- Released to the existing production server using a 48-file whitelist; no environment files, dependencies or unrelated worktree changes were deployed.
- Backup: `/var/backups/flowershop/purchasable-variants-20260828-1`, containing verified compressed SQL and code archives.
- Release archive SHA-256: `346415a4bddf365cc3baf891845775990a597387aa1e4c36c7d82fc24751ccf3`.
- All 37 baseline hashes matched before extraction; all 48 release file hashes matched afterward. The variant migration completed on production MySQL; its composite stock-hold unique index and new columns were checked.
- Configuration/routes/views rebuilt; queue restart signalled; nginx, PHP-FPM and checkout-recovery timer active. Debug remains off; database ping succeeds; zero failed jobs.
- Post-release health, home, catalogue sorting/filtering, search, cart, admin login, image fallback and build CSS checks returned HTTP 200. Guest variant administration redirected to login (302). The production error-log count remained 1245; no new production errors were observed in these checks.
- Production catalogue, variant, order and invoice counts remained zero. No catalogue was automatically published. Merchant setup must supply products as well as the two launch-check blockers: seller identity/address and payment credentials.
- This is a successful feature deployment, **not** commercial-readiness certification or full WooCommerce parity.
