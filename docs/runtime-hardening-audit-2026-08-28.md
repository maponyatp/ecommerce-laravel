# Runtime and database hardening — 28 August 2026

## Outcome and limits

Scoped application, database-index and dependency repairs have been deployed to the existing shop server. This is not a guarantee of zero defects, full commerce-platform parity or readiness to accept real customer payments. No real orders, charges, refunds, shipments or emails were created for testing.

## Confirmed defects repaired

| Area | Finding and repair |
| --- | --- |
| Wishlist sharing | A unique index on `share_token` prevented sharing a list with multiple products. A reviewed migration replaces that index with a non-unique lookup index. Existing links and the unique customer/product constraint are preserved. Sharing is serialized per owner. Rollback refuses to discard multi-product shares. |
| Deleted wishlist products | Soft-deleted products caused null dereferences and HTTP 500 responses. Owners now see an unavailable-item message and can remove the item. Shared lists omit retired products. Removal remains scoped to the authenticated owner. |
| Wishlist privacy and images | Wishlist responses now use private/no-store, no-referrer and no-index headers. Cards use the product's actual image or existing fallback instead of a missing hard-coded placeholder. |
| Delivery inputs | Legacy create/update endpoints accepted amounts beyond MySQL `DECIMAL(8,2)` capacity. Both endpoints now reject overflow, negative, malformed and over-precision values before writing. Admin forms also reject silent rounding beyond two decimal places. |
| Admin pagination | Query-string-driven pages rendered Livewire pagination actions without the corresponding page handlers. Customer lists/history, inventory/history, returns/history, variant drafts/history and CMS revisions now use ordinary GET links with a stable page URL. Filters and selected record context survive navigation. Numbered links are regenerated after setting the URL, including after action re-renders. |
| Test drift | The unknown-currency invoice was correct; an old test expected lower-case wording. Its assertion now matches the current warning while still rejecting a fabricated ZAR amount. No invoice calculation or currency behavior was weakened. |

The same pagination partial is applied to local refund and staff-security views, but those unfinished modules remain **undeployed**. The production returns template received only the pagination changes; a local link to the undeployed refunds module was deliberately excluded.

## Dependency repairs

The initial locked PHP audit reported 12 advisories affecting Guzzle and CommonMark. Reviewed same-major updates changed only four PHP packages:

- `guzzlehttp/guzzle`: 7.13.2 → 7.15.5
- `guzzlehttp/promises`: 2.5.0 → 2.5.3
- `guzzlehttp/psr7`: 2.12.3 → 2.13.1
- `league/commonmark`: 2.8.2 → 2.10.0

The maintainers document the relevant [Guzzle host-validation fix](https://github.com/guzzle/guzzle/security/advisories/GHSA-v5mv-p594-2x33) and [CommonMark unsafe-link fix](https://github.com/thephpleague/commonmark/security/advisories/GHSA-29pj-957v-52mc). An advisory match is not proof that the live application was exploited.

Frontend build dependencies Vite, PostCSS and Nano ID were updated within the existing dependency constraints, including necessary transitive build packages. The npm audit initially reported three high-severity affected dependencies and now reports none. The patched build succeeds. The existing large JavaScript chunk warning remains a performance consideration, not a build failure.

No production frontend assets were replaced: the local build includes other unreleased theme work. The dependency lock update is available for subsequent reviewed builds. The deployed PHP dependency audit reports no known advisories. Audits are point-in-time checks, not exhaustive vulnerability assessments.

The existing GitHub security workflow now audits the lockfile explicitly and includes an npm lockfile audit. It retains read-only repository permissions and does not suppress security failures. Execution of the updated GitHub workflow has not been independently verified in this release.

## Verification

- Initial full isolated suite: **1,180 tests, 4,496 assertions, zero errors, two failures**. The failures exposed admin pagination and the stale invoice warning assertion described above.
- Wishlist repro: multi-product sharing and deleted-product rendering both produced HTTP 500 responses before the fix. Delivery repro showed unsafe numeric input being accepted before validation was added.
- Initial focused wishlist/delivery regression: **61 tests, 367 assertions passed**.
- After dependency updates, a 225-case regression exposed a numbered-link URL issue in the new pagination partial. It was corrected; the dedicated pagination rerun passed **2 tests / 19 assertions**. The clean repeat of the full 225-case regression passed as recorded below. Do not combine overlapping suites into a unique coverage total.
- **11 JavaScript tests passed** for checkout quotes, delivery-window form behavior and safe fragment navigation.
- Scoped PHP formatting checks passed. All **794 local PHP source/test files** parsed; dependency JSON and the security workflow YAML validated. No source merge markers remain.
- Production PHP platform requirements passed. The MySQL migration dry run showed only the intended wishlist index replacement.
- Production post-release scan: **622 PHP files parsed**, no syntax errors, no missing registered route handler methods, **139 InnoDB tables**, **200 foreign-key relationships checked**, no orphaned relationships and no failed jobs.
- Home, health, products, cart, admin login and shared-wishlist HTTP checks passed. Private wishlist/admin pages redirected unauthenticated visitors to login.
- Read-only production rendering verified retired wishlist items and ordinary pagination links. The database transaction was rolled back without writes.
- Browser automation could not connect because its runtime was missing a required module. Browser-console, visual/mobile and accessibility acceptance remain unverified; server rendering is not equivalent to browser testing.

### Final clean regression

**225 tests / 1,483 assertions passed**, with zero errors or failures, after the final fixes and PHP dependency updates. This covers wishlist lifecycle, delivery validation/scheduling, checkout/recovery, gateway validation/settings, customer directory/navigation, email branding, invoices, inventory, variants, CMS publishing, returns, local refund workflow and registered admin runtime surfaces. The JavaScript suite also passed all **11 tests** after the frontend dependency update.

The original 1,180-case baseline was not repeated in full after the final changes; the clean 225-case run is the final targeted regression evidence. The live server, dependency audit, migration status, release hashes, private wishlist headers and service health were rechecked successfully after deployment.

## Deployment and recovery

- Thirteen explicitly reviewed application/view/migration/lock files; four PHP package updates; no blanket source deployment or migration run.
- Private verified database/code/vendor backup: `/var/backups/flowershop/runtime-hardening-20260828-1`.
- Archive SHA-256: `33152b8541a09c3e898e16f73d6c3b7aa17235fab3d7cf452ccb7023cf2c922b`.
- Migration: `2026_08_28_180000_fix_wishlist_share_token_index`; successfully applied on production MySQL.
- Released file hashes matched. `.env` and the production asset manifest were unchanged. Views were cached as the web-server account, queue restart signalled and PHP-FPM reloaded.
- Production error count remained **1,251**, with the latest entry still the prior diagnostic error at 18:14:53 UTC. No new application exceptions were observed during deployment checks. Historical logs were preserved.
- Product, order, invoice, transaction, wishlist and delivery-method counts remained zero during these checks. This is not a populated-catalogue load test.
- Recovery preserves failed dependency files and restores the backed-up code/dependencies; it does not overwrite customer data. The compatible forward wishlist index is retained if already applied. Never blindly restore an old database over new customer activity.
- Source publication is restricted to the Maponya review branch. GitHub `main`, the old upstream repository, credentials, operational backups and unfinished production modules are outside this release.

## Still required before client payment/delivery acceptance

The live readiness service still flags four configuration blockers: monitored support email, legal seller name/address, VAT registration decision and merchant payment credentials. There are no products or delivery methods yet.

The merchant must provide actual catalogue/stock, agreed delivery coverage/rates/capacity, business details and supported payment credentials, then approve real end-to-end payment, receipt, settlement/refund and delivery tests. Saving credentials alone is not verification.

PayFast/Peach/Ozow checkout and automated DSV quotes/bookings/labels/tracking are not implemented by this release. Recorded external refunds/credit notes, theme library and staff-security work present in the review branch are not automatically live. Production-like concurrent MySQL/load tests, backup restoration, MFA/access review, monitoring, credential rotation and browser/mobile/accessibility acceptance remain separate launch gates.
