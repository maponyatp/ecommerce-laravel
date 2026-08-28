# Flower gifting and checkout usability — 29 August 2026

## Outcome

Deployed a three-file checkout update to `https://shop.maponya-tech.com` at approximately 00:41 South African time on 29 August (22:41 UTC on 28 August).

Flower gifting now uses normal recipient delivery, without requiring supplier selection or applying the old dropshipping premium. Digital-only checkout also exposes the billing fields that its server-side validation can require.

## Gaps closed

- **Gift notes were hidden behind an unfinished supplier-ordering option.** Customers now see an optional gift message directly under delivery details. The existing delivery recipient name/phone/address are used; duplicate recipient-name and recipient-email inputs and the placeholder supplier selector were removed from the public form. The note is limited to 2,000 characters and retains server-side validation.
- **Gifting could select a different fulfilment workflow or add an unrelated premium.** New public checkout orders are explicitly normal store-fulfilled orders, with the recipient name copied from the delivery contact. They retain the selected delivery rate, stock reservation, payment settlement, receipt and packing-document workflows. The billing/customer email remains separate from the delivery recipient; receipts are sent to the buyer.
- **Forged or old forms could initiate unsupported supplier purchasing.** Both quote and submission reject active dropshipping flags and nonempty supplier identifiers with an actionable message. An explicitly disabled legacy flag (`0`) remains compatible. The shared pricing service also rejects a dropshipping request. Public checkout no longer queues a supplier-purchasing job.
- **Digital-only orders hid billing inputs.** The billing section was inside the physical-delivery condition even though server-side validation could require those fields. It is now available for both digital and physical checkouts, without showing physical-delivery/gift controls for digital-only orders. No VAT rates, legal rules or invoice classification logic changed.

This is not a completed dropshipping integration. The public selector was removed because it exposed an unfinished workflow; supplier API configuration, services, jobs and staff API routes remain unchanged. Their credential handling, provider contracts, retries and purchase idempotency require separate review before real supplier use. Existing orders and historical supplier records were not rewritten or deleted.

## Verification

- Initial gift-checkout reproduction: **14 tests, 11 failures** covering the public form, recipient recording, unsupported supplier submissions and the pricing-service gate.
- Broad regression: **196 tests, 1,174 assertions passed**, covering gift checkout, amount/input boundaries, checkout/recovery, destination rules, delivery scheduling, cart integrity, fulfilment documents, invoices, access restrictions and existing staff supplier APIs/services.
- The digital-billing visibility fix was added during the broader run. A subsequent **15-test / 167-assertion focused suite passed** on the final checkout code, including the new digital-only billing test. It overlaps the broad suite; do not add the counts as unique tests.
- **11 JavaScript tests passed** for checkout quotes, delivery-window selection and safe storefront anchor navigation.
- Scoped PHP formatting, syntax and diff-whitespace checks passed. The paid-gift test used a fake iKhokha response and synthetic settlement through the existing payment service. The normal-gift tests verified buyer-only receipts, normal packing eligibility, unchanged delivery amounts and no supplier job dispatch.
- Local tests used isolated in-memory SQLite, fake notifications/queues and fake or blocked HTTP. No real payment, supplier order, shipment or email was made.
- Production read-only verification rendered the physical checkout with its gift note and the digital checkout with billing fields, and checked supplier-option validation without creating database records or sending requests to a provider.
- Post-release checks: **622 PHP files parsed**, no syntax errors or missing registered route handlers; **139 InnoDB tables**, **200 foreign-key relationships checked**, no orphans and no failed jobs.
- Home, health, products, cart and admin login returned HTTP 200. Empty-cart checkout and unauthenticated private launch checks redirected with HTTP 302.
- Error count remained **1,251**, with the latest entry still 28 August 18:14:53 UTC. No new exceptions were observed in deployment checks. Historical logs were preserved.

The entire application suite, browser/mobile/accessibility acceptance, production-like concurrency/load testing and real merchant/carrier tests were not completed by this release. Server-rendering and automated tests are not a guarantee of zero bugs.

## Release and recovery

Only these reviewed files were deployed after comparison with the previous production contents:

- `app/Services/CheckoutPricingService.php`
- `app/Http/Controllers/CheckoutController.php`
- `resources/views/checkout/checkout.blade.php`

Before/after file hashes and archive hash matched. Archive SHA-256: `c9c9b33b0fc84666ca8643f0bba38921db2585ba34d28135a58704c90fda89e4`. Private rollback code backup: `/var/backups/flowershop/flower-gift-20260829-1/code.tar.gz`.

No schema migration or customer-data rewrite occurred. `.env`, the compiled frontend asset manifest, Composer files, supplier configuration/service/job/controller and staff API routes retained their exact prior hashes. Blade templates were cached as the web-server account, PHP-FPM reloaded and queue restart signalled. Rollback was available to restore only the prior three files; it was not required. Operational files and backups remain outside Git.

Source publication is restricted to the Maponya review branch; GitHub `main` and the former upstream are unchanged.

## Still required before launch

The live store still has zero products and shipping methods. Automatic launch blockers remain support contact email, legal seller details, VAT registration decision, catalogue and supported merchant credentials. Actual products/stock, agreed delivery coverage/rates/capacity and real payment/receipt/settlement/refund and fulfilment acceptance are required.

PayFast/Peach/Ozow checkout and DSV quotes/bookings/labels/tracking remain unfinished. This release does not deploy the review branch's theme library, recorded-refund/credit-note or staff-security work. Monitoring, MFA/access review, secret rotation, successful backup restoration and wider operational acceptance also remain open.
