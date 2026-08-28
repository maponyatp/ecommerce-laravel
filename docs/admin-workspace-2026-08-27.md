# Admin workspace release — 27 August 2026

## Delivered

- Consistent modern light theme for the admin shell, login, tables, forms, actions, cards and mobile navigation.
- Navigation grouped into store operations, catalogue, online store, delivery, marketing/reports, customer support, settings and advanced tools. Existing access checks remain enforced; grouping does not change permissions.
- Store overview with real 30-day paid ZAR order totals, order attention counts, low stock, recent orders and setup links. Other currencies are excluded explicitly; totals are not represented as net revenue.
- Store design/settings organised into Store details, Brand & theme, Homepage, Search & social and Footer tabs. Existing setting keys and saved content are preserved.
- Super-admin-only **Settings → Payments & delivery**, `/admin/store-integrations`, with iKhokha and DSV credential forms.
- Encrypted credentials, write-only fields, blank-value preservation, explicit removal, stale-edit detection and secret-free change history. Saving one provider does not discard unsaved fields for the other.
- iKhokha enabled credentials feed the existing gateway. Explicit stored configuration supersedes environment credentials and fails closed on unreadable encrypted data. Known pending payments block credential changes/disablement.
- DSV settings are storage only. The interface explicitly states that quotes, bookings, labels and tracking are not connected; saving credentials does not create shipments.
- Refreshed public Filament assets from the installed vendor version, including the missing menu-builder script.

## Verification

- PHPUnit: **89 tests / 377 assertions**, no failures, errors or skips. Includes admin listing/create routes, settings saves, permissions, encrypted credentials, stale edits, payment configuration/signing, webhook validation, reconciliation, checkout recovery and commerce regressions.
- JavaScript tests: **11 passed**.
- Scoped Pint passed; production asset build passed (existing bundle-size warning remains).
- Browser skill used for authenticated local desktop/mobile visual verification, grouped navigation, credential forms, responsive layout and settings tabs. Temporary mobile viewport was reset. No production credentials were entered in the browser.
- Production: migration 000024 complete, no pending migrations, database ping successful, debug off, zero failed jobs, no integration records or integration-change records created by deployment.
- Live HTTP: `/health`, `/`, `/admin/login`, both new compiled stylesheets and menu-builder script return 200; guest `/admin` and `/admin/store-integrations` redirect to authentication.
- All 16 deployed source/build file hashes and four sampled vendor asset hashes match the local release.
- Production nginx, PHP-FPM and checkout-recovery timer active after release.

## Deployment record

- Backup: `/var/backups/flowershop/admin-workspace-20260827-1` (compressed SQL and code/assets; gzip integrity checks passed).
- Initial archive SHA-256: `cdd000db8a3ae53ce35f5d04ec94883347fd2b0cd27ef3615dea2b2cffc9386b`.
- A missing generated storefront CSS dependency was detected before reopening and uploaded separately, hash `e5cdb65d10aabf7b5020d18600a40b7a4c64ec71daec92f67a2b8969aeb34c82`.
- Complete local archive including that asset: `work/admin-workspace-complete-20260827.tar.gz`, SHA-256 `df9cc73847a90c4566464eb2f21c0ce06369d880013c61217b2264a3845e4860`.
- Static asset publishing initially failed under the runtime account because public directories were read-only. Publishing under the deployment account resolved it; existing directory permissions were not loosened. The two diagnostic CLI errors increased the historical log counter from 1243 to 1245. Post-release HTTP checks added no further errors.
- No credentials, customer orders, payments, emails or courier shipments were created. No unrelated worktree changes were deployed.

## Remaining boundaries

This is a verified admin UX/settings release, not a certification of Shopify-equivalent capability or full launch readiness. Live merchant payment/settlement testing, actual DSV API integration and acceptance tests, gateway refunds/credit notes, purchasable variants and broader international checkout remain separate work. Current checkout is ZAR with South African delivery. Full load/security/restore testing remains outstanding. Keep the application encryption key backed up securely; stored credentials depend on it.
