# Runtime and database repairs — 27 August 2026

## Findings and fixes

| Confirmed defect | Repair |
| --- | --- |
| CMS page creation threw a missing Textarea class error | Added the correct Filament form-component import; authenticated admin listing/create-route rendering is covered by regression testing. |
| Array-shaped keywords, filters, sorting and pagination could crash catalogue/search pages | Shared form-request validation now rejects malformed inputs. Valid product search and price filters remain supported. |
| Malformed keywords crashed the shared header on unrelated storefront pages | Non-text keywords are ignored when rendering desktop/mobile search fields; search endpoints still validate submissions. |
| Unconfigured or unknown social-login providers threw runtime exceptions | Unknown/disabled providers return 404; supported but unconfigured providers return a private 503 response with email/password guidance, without provider network calls. Login/registration advertise only configured providers. |
| OAuth account-link confirmation dereferenced an unauthenticated user or threw on an expired request | Linking prompt/confirmation require authentication; expired requests return validation errors. Existing configured-provider callback tests remain covered. |
| Customer login advertised demo credentials | Removed the demo credential block and corrected its account sign-in guidance. No customer/admin passwords or accounts were changed. |
| Review votes referenced missing database columns | Migration 000022 adds counters and a unique per-review/per-user vote ledger. Repeated votes are idempotent; switching a vote moves one count, under a transaction and review row lock. Pending reviews cannot receive public votes. |
| Public review responses loaded full reviewer records | Reviewer relationship now selects only ID/name, excluding email and other account fields. |
| Inventory could change even when its audit insert failed | Product row lock and one transaction cover inventory and before/after audit quantities. Invalid/overflow stock values are rejected; failed audit writes roll back stock. |
| Renaming a legacy setting to an existing name raised an SQL uniqueness error | Validated unique names and a duplicate-write guard return validation errors; only validated attributes are saved. |
| Payment-reference edits bypassed create-time validation | Edits now enforce the same processor-reference shape and length bounds. These references are not represented as gateway-verified payment methods. |
| Placeholder/encoded fragment links could raise JavaScript selector errors | Fragment IDs are decoded safely and looked up as literal IDs. Invalid/missing targets retain native behavior; valid homepage sections still scroll. |

## Database and production inspection

Read-only preflight: MySQL responded successfully; 132 tables used InnoDB; no pending migrations or failed jobs were found. Checked order-item/order/product, return/order/item and payment/order relationships had no orphaned rows. Production debug was disabled, services were active, and filesystem usage was 16%.

The historical duplicate `orders` SQL alias was traced to an earlier temporary diagnostic script, not the current request path. All 21 current sales/customer/inventory reporting query checks passed on production MySQL. Initial production syntax scan covered 614 PHP files and found no parse errors or missing registered route handler methods. These checks do not establish full physical database integrity or concurrency/load behavior.

Expanded pre-release integrity check inspected **all 186 declared foreign-key relationships**, with zero orphaned records found and no large tables skipped. This was read-only; no records were deleted or automatically reconciled.

## Verification

- Initial broad run: 962 tests. It exposed the review schema defect and outdated fixtures/expectations as well as test-state leakage. Protected APIs/inventory/settings/review approval still require authorization; retired unsafe Stripe/subscription endpoints were not re-enabled to satisfy old expectations.
- Test fixtures now supply staff permissions/token scopes where appropriate, authenticate owners, use synthetic processor references and assert that unconfigured subscription processing fails closed.
- Test setup blocks stray outbound Laravel HTTP calls and resets Livewire request state between tests. This fixes cross-test asset injection in the private packing-document assertion without weakening that assertion.
- Focused final repairs: 59 tests / 215 assertions passed. Extended frontend/OAuth checks: 83 tests / 340 assertions passed. Shared-header/OAuth regression file after the final header fix: 12 tests / 129 assertions passed. These suites overlap and must not be added together.
- All 11 checkout/delivery/fragment JavaScript tests passed. Asset build passed with the pre-existing large-chunk warning; generated asset hashes remained unchanged.
- Final full-suite result: **981 tests / 3,140 assertions passed**, with no errors, failures or risky tests. The additional shared-header case was added after full-run discovery and passed separately within the 12-test regression-file run above.
- Browser webview failed to attach; visual/mobile/accessibility and real browser-console verification remain incomplete.

## Release

Twenty scoped runtime/view/migration files. Tests, synthetic fixtures, documentation and credentials are excluded. Existing source/build hashes are verified before deployment, all released source hashes afterward. No payment credentials, legal/tax settings, customer records or real shipments/payments are modified for testing.

- Verified database/code backup: /var/backups/flowershop/runtime-errors-20260827-1
- Archive SHA-256: 21b12793eedb29358fa606916a6887942654a0cb4a07c0753d0d06c3f5b779b3
- Asset manifest (unchanged): 858a7c3a33bf7855cbc9365be87e35a2f06c9f64a60d50421701d0da4d224392
- Migration: 2026_08_27_000022_add_review_vote_records; MySQL SQL dry run passed. Production reviews were zero and both missing counter columns/vote table were confirmed before release.
- Deployment: completed successfully after backup and regression verification.

Migration 000022 completed on production MySQL. All 20 released file hashes and the unchanged asset manifest matched. Configuration/routes/views were cached, queue restart signalled, PHP-FPM reloaded and checkout recovery restarted. The application is live; nginx, PHP-FPM, MySQL and the recovery timer are active.

Post-release HTTP checks passed: 20 valid public-page/header cases returned 200; five malformed catalogue queries returned 422; unconfigured Google OAuth entry/callback returned controlled 503 responses; unauthenticated CMS creation/returns requests returned 401. These deliberate validation/unconfigured-provider responses are not server exceptions.

Post-release database checks: 133 InnoDB tables, all 188 foreign-key relationships checked, no orphans, no pending migrations and zero failed jobs. All 21 reporting query checks passed again. All 619 PHP source/config/route/migration files passed syntax parsing, with no missing registered route handlers. Review/vote/return/refund counts remained zero; no synthetic production records were created.

Application error count remained 1,243 before and after verification; the latest entry was still the historical diagnostic-script alias error at 13:13:30 UTC. No new production application exceptions appeared during these checks. Historical logs were preserved.

Both iKhokha and Stripe configuration checks remain false. This release does not activate paid checkout, real refunds or DSV.

Rollback requires a reviewed backup and reconciliation plan. Do not blindly restore an old database over new customer activity; automatic destructive rollback of vote history is blocked.

## Still not certified

Passing automated tests is not proof of zero bugs. Real payment settlement/refunds and DSV delivery remain unverified/unconfigured; purchasable variants, international-market expansion, full browser/mobile/accessibility checks, security/load/concurrency assessment and a backup-restore drill remain separate work. Paid checkout still requires merchant payment credentials.
