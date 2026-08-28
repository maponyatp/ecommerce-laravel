# Checkout amount and delivery validation — 29 August 2026

## Outcome

Four reviewed application/view files were deployed to `https://shop.maponya-tech.com` at approximately 00:22 South African time on 29 August (22:22 UTC on 28 August). This closes specific checkout validation gaps; it does not establish full production readiness or platform parity.

## Fixes

### Protect the invoice/order amount boundary before payment

Production schema inspection confirmed that `orders.total_amount` supports `DECIMAL(12,2)`, while invoice totals and order shipping, tax and discount components use `DECIMAL(10,2)`. Previously, a basket could be accepted for payment even though its eventual invoice amount could not be stored.

Checkout now rejects negative, non-finite or out-of-range calculated amounts before creating an order, stock hold or payment link. Subtotal, discount, shipping, tax and the combined total are checked against the shared supported limit of **99,999,999.99**. The exact boundary remains quoteable. This is a storage-safety boundary, not a claim that any payment provider will accept a transaction of that size; provider limits and merchant acceptance remain separate.

Shipping and tax are rounded to their stored two-decimal values before deriving the final total, so the quoted components and total remain consistent. No tax rates, VAT classification or legal rules were introduced or changed. Existing historical orders and invoices were not rewritten.

### Match South African postal validation across setup and checkout

Both quote and payment submission require exactly four ASCII digits for a supplied delivery postal code. Leading zeros are preserved as text. Previously, unrestricted delivery methods could accept alphabetic or incorrectly sized codes even though delivery setup requires four-digit codes.

The checkout field now includes matching length/pattern constraints, a numeric keyboard hint and explanatory text. Server-side validation remains authoritative. This verifies format only, not that a postal code or address exists or is serviceable.

### Count only physical delivery weight

Shipping weight now excludes digital lines and rejects invalid physical weights rather than silently calculating a charge from them. A service-level mixed-cart regression demonstrated that a digital line carrying weight metadata could incorrectly exceed a method's weight limit and alter its charge. This is defensive handling of that data shape, not evidence of historical customer overcharging; current simple digital products do not have a catalogue weight column.

## Verification

- Initial isolated reproduction: **17 tests, 13 failures**, covering oversized subtotal/submission/combined total, malformed postal codes, digital shipping weight, invalid calculated tax and shipping overflow.
- Final expanded regression: **172 tests, 998 assertions passed**. Coverage includes checkout boundaries, checkout/recovery, international-storefront restrictions, delivery scheduling, cart integrity, shop operations, launch safety, shipping service, purchasable variants and invoice documents.
- **11 JavaScript tests passed** for checkout quote handling, delivery windows and storefront anchor navigation.
- Scoped PHP formatting/syntax checks and diff whitespace checks passed.
- Tests used an isolated in-memory SQLite database, synthetic catalogue data, fake notifications and fake/blocking HTTP clients. No real payment, email or carrier booking was made.
- Production schema and synthetic amount/postal/weight verification passed inside a **read-only MySQL transaction**. These checks did not create customer or catalogue records.
- Post-release audit: **622 PHP files parsed**, no syntax errors or missing registered route handlers; **139 InnoDB tables**, **200 foreign-key relationships checked**, no orphans and no failed jobs.
- Public home, health, products, cart and admin login returned HTTP 200. Empty-cart checkout and unauthenticated private launch checks redirected with HTTP 302 as expected.
- Production error count stayed at **1,251**, with the latest entry still 28 August 18:14:53 UTC. No new exceptions were observed in the deployment checks. Historical logs were retained.

The entire application suite, real browser/mobile/accessibility acceptance, production-like concurrent checkout/load testing and merchant/carrier acceptance were not completed by this release. These checks cannot guarantee that every bug is absent.

## Release and recovery

Only these files were replaced after comparing their previous production contents with the reviewed source baseline:

- `app/Services/CheckoutPricingService.php`
- `app/Services/ShippingService.php`
- `app/Http/Controllers/CheckoutController.php`
- `resources/views/checkout/checkout.blade.php`

Before/after file hashes and release archive hash were verified. Archive SHA-256: `e8b16e3acbef23043fb1d9e31b386a7047bda1ac9ae74f6dec5661ff7767ec65`. Private code backup: `/var/backups/flowershop/checkout-boundary-20260829-1/code.tar.gz`.

Blade views were cached as the web-server account, PHP-FPM reloaded and queue restart signalled. `.env`, frontend asset manifest and Composer files were unchanged. No schema migration, credential change, payment/delivery request or customer-data rewrite occurred. Rollback was available to restore only the previous four files without restoring an old database; it was not needed. Operational files and backups remain outside Git.

Source publication is restricted to the Maponya review branch. GitHub `main` and the former upstream are outside this release.

## Remaining launch requirements

The live store still has no products or shipping methods. Its five automatic blockers remain support contact email, legal seller details, VAT registration decision, catalogue and supported merchant credentials. Merchant-approved delivery coverage/rates/capacity, real payment/receipt/settlement/refund and fulfilment tests are still required.

PayFast/Peach/Ozow checkout and actual DSV quotes/bookings/labels/tracking remain unfinished. Review-branch theme library, recorded-refund/credit-note and staff-security modules were not deployed. Monitoring, MFA/access review, secret rotation, backup restoration and broader operational acceptance remain open. See the preceding [launch-safety release](launch-safety-release-2026-08-29.md) for that release's boundaries.
