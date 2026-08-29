# Catalogue API safety release — 2026-08-29

## Outcome

The production catalogue API now rejects malformed filters and write identifiers without runtime or database exceptions. Catalogue price filters and sorting use the same effective pricing rules as the storefront, including zero-priced `free` products and unavailable legacy pricing modes. Private downloadable-file paths are no longer serialized or assignable through the product API.

Authorized store API responses are marked `Cache-Control: no-store, private` and `Pragma: no-cache`. Product write fields now have database-compatible length, integer, decimal and expiry bounds. Digital file attachment remains an authenticated admin upload workflow; the JSON product API cannot attach an arbitrary server path.

## Verification

- Focused catalogue boundary suite: 22 tests, 108 assertions.
- Catalogue, product API, pricing, inventory and cart regression suite: 104 tests, 725 assertions.
- PHP syntax checks passed for every changed PHP file.
- Production source matched Git `HEAD` before release for all three replaced files.
- Production read-only verification exercised malformed queries, effective-price SQL and private-path serialization.
- Storefront, health, catalogue, cart and admin login returned HTTP 200.
- Checkout and readiness redirects returned HTTP 302 as designed.
- Unauthenticated JSON catalogue API returned HTTP 401.
- Broad production audit checked 139 InnoDB tables, 200 foreign keys, 623 PHP files and all route handlers with no orphan, syntax or route-handler errors.
- No emails, payments, catalogue writes or database migrations were performed.

The first release attempt passed its code and database checks but used a curl request without an `Accept: application/json` header. It received the normal HTTP 302 login redirect instead of the test's expected JSON 401 and automatically restored the original three files. The second release used the correct API request header and completed successfully. A final one-file edge release added array-safe cross-field price validation. The retained successful backups are `/var/backups/flowershop/catalogue-api-20260829-2` and `/var/backups/flowershop/catalogue-api-edge-20260829-1`.

## Merchant readiness remains blocked

This safety release does not make the empty store commercially launch-ready. Production readiness still reports missing contact email, seller identity, VAT status, catalogue and payment configuration. Production currently has no products or shipping methods, and real payment and delivery acceptance tests remain outstanding.
