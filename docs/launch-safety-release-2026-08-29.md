# Launch-readiness and delivery safety release — 29 August 2026

## Outcome

Deployed five reviewed PHP files to `https://shop.maponya-tech.com` at approximately 00:04 South African time on 29 August (22:04 UTC on 28 August). This is a limited safety release, not a declaration that all commerce gaps are closed.

### Gaps closed

- **Empty catalogue could pass preflight:** launch checks now explicitly flag a missing catalogue, including a catalogue containing only soft-deleted products. The admin check links to product management. Catalogue presence is deliberately not described as proof that items are purchasable; stock, prices, options and digital files still require acceptance testing.
- **Unusable delivery methods could appear at checkout or pass readiness:** initial checkout display and repricing now share persisted-configuration validation. Inactive methods, negative/out-of-range rates, unspecified or invalid maximum weights, and malformed postal-code lists are not advertised. This also prevents a malformed scalar postal-code value reaching `in_array()` and causing a type error. Empty/null coverage lists retain their existing all-South-African-postcodes meaning; coverage has not been broadened.
- **Scheduled delivery could pass readiness without bookable capacity:** at least one valid method must be unscheduled or have an open future window with remaining capacity. This is a point-in-time check, not a reservation or guarantee of coverage for every basket.
- **Legacy delivery activation accepted an unspecified maximum weight:** activation now requires an explicit maximum. Inactive drafts can remain incomplete. Existing incomplete methods are preserved, not deleted or silently assigned an unlimited weight. Zero-price and zero-weight configurations remain valid.
- **Unused address-verification placeholder could transmit personal data:** the method no longer makes an HTTP request to a placeholder provider. It returns `null` (unverified). No production caller was found; this is not evidence that customer addresses were previously transmitted, and it does not implement DSV/address verification.

## Verification

- New regression suite before the fixes: **14 tests, 11 failures**, reproducing the missing catalogue check, invalid delivery options, missing scheduled capacity and placeholder transmission behavior.
- Related post-fix regression: **110 tests, 624 assertions passed** across launch safety/readiness, admin usability, shipping services/validation, shop operations, delivery scheduling, checkout and checkout recovery. No real payment, delivery booking or email was sent. Tests used isolated in-memory SQLite and blocked stray HTTP requests.
- Scoped PHP formatting and syntax checks passed. Formatting after the regression changed whitespace and optional constructor parentheses only.
- Production synthetic configuration checks passed under a **read-only MySQL transaction**, including malformed postal-code and missing-weight rejection and disabled placeholder verification.
- Post-release scan: **622 PHP files parsed**, no syntax errors or missing registered route-handler methods; **139 InnoDB tables** and **200 foreign-key relationships** checked with no orphaned relationships; no failed jobs.
- Public home, health, products, cart and admin login returned HTTP 200. Unauthenticated access to private launch checks redirected to login (302).
- Application error count remained **1,251**; the latest logged exception remained the earlier 28 August 18:14:53 UTC entry. No new exceptions were observed during these checks. Historical logs were preserved.
- The complete application suite, browser/mobile/accessibility testing, concurrent production-like load testing and real merchant/carrier acceptance were not repeated or completed by this release. Passing these checks does not prove the absence of all bugs.

## Release boundaries and recovery

Only these files were deployed, after comparing their previous production contents to the reviewed source baseline (line endings normalized for comparison):

- `app/Models/ShippingMethod.php`
- `app/Services/ShippingService.php`
- `app/Services/StoreReadinessService.php`
- `app/Support/StoreSetupGuide.php`
- `app/Http/Controllers/ShippingController.php`

Exact live hashes were checked before replacement and release hashes afterward. Private code backup: `/var/backups/flowershop/launch-safety-20260829-1/code.tar.gz`. Release archive SHA-256: `6866accd7a8cd7037f59c9fc16d5cff3b0ff99605a545de160f09d155ac7287d`.

No database migrations or customer-data changes were performed. `.env`, frontend asset manifest and Composer files remained unchanged. PHP-FPM was reloaded and queue restart signalled. The release script can restore the prior five files on failure without restoring an old database over customer activity; rollback was not needed. Private operational artifacts remain outside Git.

## Remaining launch requirements

The live preflight now correctly reports **five configuration blockers**: monitored support email, legal seller details, VAT registration decision, catalogue and supported payment credentials. Products and shipping methods both remained at zero. Physical-product delivery setup will become an automatic blocker when physical products are added; an empty store does not imply that delivery has been configured.

The merchant must supply actual products/stock, legal business details, agreed delivery coverage/rates/capacity and merchant credentials, then approve real payment, receipt, settlement/refund and delivery acceptance tests. Do not create invented business information, rates or merchant credentials to make checks green.

PayFast/Peach/Ozow checkout and DSV quotes/bookings/labels/tracking remain unfinished. This release does not deploy the review branch's theme library, recorded-refund/credit-note or staff-security modules. MFA/access review, secret rotation, monitoring, backup restoration and browser/mobile/accessibility acceptance remain separate requirements.

Source changes belong on the Maponya review branch only. No old upstream access or replacement of GitHub `main` is part of this release.
