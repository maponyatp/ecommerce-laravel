# Product pricing safety — 29 August 2026

## Confirmed gaps and fixes

The product editor offered Free and Pay What You Want, while cart hydration and
stock reservation used the stored fixed price. A legacy free product could therefore
charge its previous price; a newly created free product could instead have a null
price and fail cart validation. Donation amounts submitted by the product page were
ignored, so the displayed purchasing flow did not match checkout.

This scoped change:

- Uses an effective zero item price for free products in catalogue labels, public
  price filters/sorting, structured-data offers, cart hydration and locked stock checks.
- Saves new or edited free products with a zero price, including creation without
  a hidden fixed-price field. Reading legacy products does not rewrite their records.
- Defaults a blank pricing type to Fixed Price. The API previously accepted null
  while the database required a non-null value, resulting in a database exception.
- Preserves normal delivery charges for free physical items and explicitly explains
  that the item is free, not necessarily the delivered order.
- Supports Fixed Price and Free in the editor/API. Donation and other unsupported
  legacy pricing modes are displayed as unavailable and blocked from new checkout;
  they are not silently charged a fixed price or advertised as working subscriptions.
- Rejects negative fixed prices, values above ZAR 99,999,999.99 and more than two
  decimal places at the admin/API boundary. The separate cart-total limit remains.
- Keeps existing orders, pending payment amounts, invoice history and live stock
  unchanged by a catalogue pricing edit. This is not a retrospective price adjustment
  or refund feature.
- Prevents product-detail and saved-comparison pages from crashing when a product
  has no category. Category-based comparison actions require a category; an already
  compared item that loses its category can be removed using Clear comparison.
  Browsing and purchasing uncategorised products remain available.

No donation values, product rows or customer data are removed. There is no database
migration. Stored suggested/minimum donation fields remain, but a complete
pay-what-you-want implementation is still outstanding.

## Verification

The initial new regressions reproduced the price/editor/API inconsistencies. Test
fixture issues (required order shipping state and numeric versus string SQLite
values) were corrected. A broader run then exposed the null-pricing database error;
the model default fixes that application defect.

- 214 existing regression tests / 1,534 assertions passed in the broader run,
  covering cart integrity, checkout recovery, variant publication/drafts, inventory,
  digital delivery, flower gifting, amount limits, customer status, catalogue API,
  admin workspace, public product pages and invoice documents.
- After fixing the null default, 54 pricing/API/inventory tests / 389 assertions
  passed. The final expanded run passed **60 tests / 431 assertions**, including
  uncategorised-product and comparison tests. These are scoped runs, not a rerun
  of the entire repository suite or a claim that every test passed in one run.
- 11 JavaScript tests passed for quoting, delivery-window controls and storefront anchors.
- PHP style checks and `git diff --check` passed.

Tests use in-memory SQLite, fake notifications/storage and blocked external HTTP.
No live card charge, refund, email or shipment is created by these checks.

## Release scope and recovery

Only eight application files are included: Product model, CartService,
StockReservationService, ProductResource, catalogue API ProductController,
product-detail view, comparison view and catalogue buy-button component. Each pre-existing live file
matched the review baseline after newline normalization; exact live hashes are
checked again immediately before deployment.

Artifact SHA-256:
`f0ed84481c7f70cfd485513632bb16b6f3ec1eae0bdd27ac76c61bbafd1a3e19`.

Final backup: `/var/backups/flowershop/product-pricing-20260829-2`.
Recovery restores only the eight backed-up files. It must not restore an old database
over customer activity. Private environment, Composer files, built assets, checkout
controller, settlement and receipt-dispatch services are outside the release.

The first deployment attempt automatically rolled back when its synthetic product
without a category exposed the comparison URL-generation bug. A diagnostic on the
restored baseline reproduced the same error, confirming it was pre-existing, not
introduced by the pricing change. Original file hashes and HTTP 200 admin login
were verified after rollback; no database restore or deletion occurred. The failed
attempt's code backup is retained at `/var/backups/flowershop/product-pricing-20260829-1`.
The corrected retry uses a separate artifact and backup directory.

## Successful production verification

The corrected release completed at **29 August 2026 00:03:49 UTC / 02:03:49 SAST**.
Read-only MySQL verification rendered four synthetic product states and their
uncategorised comparison pages, and checked five effective-price SQL cases without
inserting products or orders. No emails, payments or shipments were initiated.

- Homepage, health, catalogue, zero-price filtering, price sorting, comparison,
  cart and admin login returned HTTP 200.
- Empty-cart checkout and unauthenticated admin readiness returned expected HTTP 302.
- All eight deployed hashes matched the reviewed artifact; protected environment,
  asset manifest, Composer files and settlement/receipt services were unchanged.
- 139 InnoDB tables, 200 foreign-key relationships and 623 PHP files were checked:
  no orphaned relationships, syntax errors or missing registered route handlers.
- Debug remained disabled, failed jobs remained zero, and the logged error count
  stayed at 1,251 with the same historical last error (28 August 18:14:53 UTC).
- Products, shipping methods, orders, invoices and payment transactions remained
  zero. No merchant or customer data was deleted or rewritten by the deployment.

Browser/mobile visual and accessibility QA, concurrent MySQL checkout tests and
actual merchant payment/delivery acceptance were not performed in this release.

Maponya's review branch is the publishing target; GitHub `main` and unfinished
theme, refund, firewall, DSV and additional payment-provider modules are not promoted.

## Operator guidance and remaining limitations

Use Catalogue → Products and select Fixed Price or Free. Review item price, stock,
delivery costs and the final checkout total. For a legacy donation product, choose
a supported pricing type and deliberately set its price before inviting customers.

This is not certification of the entire store. Actual catalogue products, delivery
methods, business/support details, VAT decision, gateway credentials and supervised
real payment/delivery acceptance remain necessary. DSV automation, additional
payment gateways, full donation/subscription workflows, restore/load testing and
broader security/browser/accessibility assurance remain outside this release.
