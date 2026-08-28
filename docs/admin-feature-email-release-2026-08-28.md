# Administrator feature-guide email release — 28 August 2026

## Outcome

Deployed the six-file, additive email release to `https://shop.maponya-tech.com`. Sent one branded test and one feature guide individually to each of the two existing administrators:

- `tumisho@maponya-tech.com`
- `hlakaniphileg@gmail.com`

All four messages were acknowledged by the configured Compcav SMTP server with TLS required. The private delivery ledger records `accepted` at 04:25:30–31 UTC (06:25 SAST). This is SMTP acceptance, **not** confirmation of inbox placement or reading. No CC/BCC recipient disclosure, passwords or gateway credentials were included.

## Scope and behavior

- Reuses the existing shared HTML/plain-text email layout and central store branding. Production currently has no uploaded logo and uses the FLORA text fallback; placeholder support contacts are omitted.
- Includes 14 reviewed feature areas with valid workspace links, current configuration checks, catalogue count, known limitations and a client-testing checklist.
- Clearly identifies unconfigured payments, non-operational DSV booking/quotes/tracking, and pending PayFast/Peach/Ozow, SMTP portal, staff-security/firewall and refund/credit-note releases. Models or local draft code are not described as deployed functionality.
- CLI command defaults to preview. It resolves existing active `admin`/`super_admin` accounts, deduplicates email addresses case-insensitively and sends separately.
- `admin_email_deliveries` provides a unique campaign/template/address claim, encrypted recipient address, recipient account, status and acceptance time. SMTP errors retain only the exception class; no credentials or raw exception messages are stored.
- An interrupted/uncertain send is not automatically retried. Inspect provider evidence before considering another campaign; a new campaign key bypasses duplicate protection by design and should not be used as a blind retry.
- This is a CLI operational tool, not a new portal email editor or a full staff-security audit module.

Preview without sending:

```sh
php artisan commerce:email-admin-guide --kind=features --campaign=admin-guide-20260828-1
```

The deployed campaign has already been sent. Adding `--send` with that same key/template/address returns the recorded result without another message. Use `--kind=test` for the separate test template. Keep the guide's reviewed feature descriptions current before future releases.

## Verification

- Final isolated regression: **22 tests, 201 assertions passed**, covering the new email workflow, existing branded application/auth emails and readiness checks.
- Checks include recipient restrictions/deduplication, revoked roles, current and pre-security schemas, private single-recipient delivery, HTML/plain text, escaping, link existence, uncertain/interrupted sends and repeat-send safety.
- Browser preview verified desktop rendering and mobile overflow. The visual check identified merged configuration checklist entries; these were corrected and a regression now requires all 15 separate checklist items. Local preview used synthetic data, not production order/customer data.
- Production rendered both templates successfully: test 12,740 bytes, guide 47,218 bytes. All 14 guide links match live routes. Both recipients can access the admin panel.
- Production smoke: health/home/products/cart/admin-login returned 200; all 13 checked private admin destinations redirected guests with 302. Database connected, debug disabled, failed jobs zero, nginx/PHP-FPM/recovery timer active.
- Error log moved from 1,247 to 1,248 entries. The sole new entry was the read-only pre-release diagnostic using the unsupported `route:list --columns` option; it was corrected to `--json`. No release, SMTP or HTTP runtime exception was observed in final checks.
- Products/orders/invoices remained zero. No real payment, refund, stock change or courier booking was executed.

## Deployment and recovery

- Backup: `/var/backups/flowershop/admin-guide-20260828-1`, root-only verified SQL/code gzip archives.
- Release archive: `work/admin-guide-20260828.tar.gz`.
- SHA-256: `fd2a8fe4d17c2c433d815ed720cf8de4aff92fe777d6e039cb64e8e3b9501f33`.
- Exact release file hashes: `work/admin-guide-after.sha256`; unchanged branding/readiness dependencies checked against `work/admin-guide-dependencies.sha256`.
- Only migration `2026_08_28_160000_create_admin_email_deliveries.php` was applied. Existing application/config/controller files were not replaced. No downtime was needed; shared views compiled successfully.
- Preserve the ledger if withdrawing the command. Do not roll back the database after sending: that would erase duplicate-send evidence. The migration deliberately refuses destructive rollback.
- Unfinished local refund/security work was preserved and excluded from the release. It still requires completion, testing and a separately reviewed deployment.

## Remaining launch prerequisites

Four automated configuration checks remain blocked: real support email, legal seller identity, VAT decision and merchant payment credentials. There are no catalogue products yet. Delivery must be configured and acceptance-tested; its current check passes because there are no physical products. Complete merchant setup, approved live payment/refund/delivery acceptance, MFA/security verification, restore drills and monitoring before public trading. This release does not certify production readiness or complete platform parity.
