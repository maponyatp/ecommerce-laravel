# Cart integrity and consistency — 27 August 2026

## Scope and findings

The variant/options review found a prerequisite cart gap: both Livewire cart classes accepted client-supplied names, prices, weights and digital flags; the cart controller used on-hand inventory rather than stock remaining after active checkout holds. Livewire's writable item array could be copied back into the session. Checkout already repriced and validated products, so this is not a claim that forged prices could complete an underpaid order.

This release unifies cart behavior; it does **not** implement variant purchasing. Existing product variants/options are still not connected end-to-end to admin management, cart line identity, variant reservations, checkout snapshots and fulfilment.

## Implemented behavior

- A shared `CartService` serves HTTP add/update/remove/clear, both current and legacy Livewire component classes, coupon calculation and checkout hydration.
- Names, prices, digital flags and weight come from database products through a fixed field allowlist. Browser/session product metadata and arbitrary extra fields are not copied into resolved cart lines.
- Quantities must be whole-number integers/digit strings from 1 to 9,999. Negative, zero, fractional, exponent, boolean, array and oversized values are rejected without a cart write. Existing plus added quantities are checked together.
- Adds and updates consider active uncommitted stock holds. Expired/released holds do not reduce availability. Cart edits never create holds, reduce inventory or create orders; the transactional checkout reservation/settlement services remain authoritative.
- Digital products still follow the existing inventory policy and require an available private file. A client-supplied digital flag cannot bypass stock checks.
- Livewire display items are locked. Mutations start from the latest session cart instead of an older component snapshot, preserving another sequential cart change and avoiding resurrection of removed lines. This does not establish correctness of truly simultaneous session writes.
- Read-only rendering refreshes current catalogue details, shows explicit problems for unavailable products and keeps them removable. Invalid quantities can be corrected, and malformed saved carts can be cleared. Invalid carts do not offer a new checkout link or pretend their total is payable.
- Read-only rendering does not rewrite the session cart or pending checkout hash. A matching unchanged cart can review its existing held/payment-review checkout through the existing resume gate; this avoids a duplicate order/payment. Expired or changed carts do not get this shortcut.
- Successful mutations invalidate the quote fingerprint; clearing/removing the last item also clears the coupon. Pending-payment references and checkout idempotency keys are retained. Coupon validation uses current database prices, not stored browser prices.
- Product cards use a CSRF-protected POST instead of the obsolete `$emitTo` call. Quantity controls have accessible names and do not bind directly to locked cart metadata.
- The cart describes its merchandise total separately from delivery, discounts and taxes calculated at checkout.

## Verification

- 18 new cart-integrity tests / 200 assertions passed, including actual Livewire actions and locked-state tampering, stock holds, missing files, stale components, deleted products, malformed carts, repricing, coupon calculation and existing-checkout recovery.
- The existing cart/checkout/commerce regression selection initially passed 40 tests / 110 assertions.
- Seven checkout/delivery JavaScript checks passed. Explicit PHP formatting/syntax and diff-whitespace checks passed. Production assets build successfully with the existing approximately 501 kB JavaScript-chunk warning.
- The complete selected commerce suite passed **296 tests / 1,310 assertions**. This is not the entire repository suite. The earlier larger run terminated unexpectedly at a checkout test under the local default 128 MB PHP memory limit; that test passed alone. The successful complete rerun used a test-only 512 MB limit (reported peak 128 MB); production memory/time limits were not changed.
- Browser preview hit 30-second PHP framework-loading timeouts both during and after the large test run. The temporary local PHP preview was restarted with a 90-second execution allowance and 512 MB memory limit, without changing application or production configuration. Local previews used an isolated SQLite database and synthetic bouquet, never production data. A fresh tab ultimately verified product-form add, cart rendering, quantity-button increments from one to two/three, ZAR 125/250/375 totals, header-count updates and item removal returning to the empty state. Desktop layout was inspected. The stock-error message was not captured before removal; manual-input blur and full mobile/accessibility coverage remain unverified in the browser. Automated tests cover stock rejection and valid/invalid quantity actions. These checks do not establish production latency. Test tabs and the temporary preview server were closed.

## Release status

Deployed to `https://shop.maponya-tech.com` after verifying the database/code backup at `/var/backups/flowershop/cart-integrity-20260827-1`. No migration was required. The release whitelist contains eight runtime files plus `public/build`; secrets, fixtures, tests, helper scripts and unrelated worktree changes were excluded. Seven existing source checksums and the build manifest were checked before replacement.

- Release archive SHA-256: `301a01b93c4507dab0a1da75297f3fadda4e5548bcf8a02c10b67f4b31f86f3c`.
- Build manifest SHA-256: `2c32c36c7b828a29f60edb1ce006df38edc2508efb8053700013b480b940baf3`.
- All eight deployed source checksums and the manifest match the tested local release.
- Config/routes/views rebuilt, queue restart requested, PHP-FPM reloaded. Application is out of maintenance; nginx, PHP-FPM and checkout-recovery timer are active.
- Read-only production checks passed for both locked cart components, legacy-class inheritance, quantity rejection, cart/checkout routes and database connectivity.
- Health, homepage, catalogue, cart, admin login and stylesheet returned HTTP 200. Empty-cart checkout and guest customer-directory requests returned 302. Health reported database connected.
- No production customer record, order, payment, refund, shipment or notification was created for verification. No credentials, gateway/delivery settings or production PHP limits were changed.

## Remaining gaps

Variant/option purchasing remains incomplete. Other open items include merchant-approved live gateway settlement/refunds, account-specific DSV configuration/testing, credit notes/returns, partial fulfilment, consent/ownership workflows, and broad browser/accessibility/security/load/restore verification. Large carts and truly simultaneous session mutations need dedicated load/concurrency testing. This is a bounded cart-hardening release, not certification that all commerce gaps are closed.
