# Payment provider settings update — 28 August 2026

## Confirmed cause and scope

Production had iKhokha and DSV credential forms only. PayFast, Peach Payments and Ozow were missing from the administration page, and their checkout adapters are not implemented. This release adds honest configuration visibility, not customer payment acceptance for these providers.

Super administrators can open `/admin/store-integrations` (Settings → Payments & delivery) and save encrypted, write-only credentials independently for each new provider. Saved credentials never return to form state. Blank fields preserve existing values; explicit removal clears them, including unreadable ciphertext. Environment changes require complete replacement credentials or explicit removal. Version checks prevent stale overwrites. Credential-field changes are audited without values. Current super-admin authorization is rechecked when saving.

All three providers display **Disabled at checkout — integration pending**. Activation is blocked both in the form and service, even if a request is forged or all credential fields are filled. No callbacks, merchant requests, payments or delivery bookings are fabricated. Implemented checkout initiation, verified callbacks, reconciliation and merchant acceptance tests remain required before activating these providers.

Official setup references are linked in the portal:

- PayFast: https://developers.payfast.co.za/
- Peach Payments: https://developer.peachpayments.com/docs/checkout-embedded-authentication
- Ozow: https://ozow.com/integrations

## Verification

- PHPUnit: **42 tests, 308 assertions passed**. Includes all new provider forms, write-only storage, validation, stale updates, revoked access, corrupted credentials, existing admin functionality, iKhokha callback validation and reconciliation.
- Artifact: `work/payment-options-20260828.tar.gz`; SHA256 `c61f3910c830fb16659b92f2d36da7d375569b8a170158f4db072ac7a0d15629`.
- Only three application files deployed: `StoreIntegrationService.php`, `StoreIntegrations.php` and the integrations Blade view. No migrations, environment edits or merchant credential changes.
- Production code and database backup verified at `/var/backups/flowershop/payment-options-20260828-1`.
- Production provider forms rendered successfully. Integration-state fingerprint, audit count, order/payment counts and failed-job count unchanged.
- A root-run render diagnostic created six root-owned cached view files, causing a login HTTP 500. Ownership was corrected to `www-data`, views rebuilt, and login returned HTTP 200. The verification procedure now runs rendering as `www-data` to prevent recurrence.
- Browser visual QA was attempted but timed out locally. Do not treat this as a completed visual/accessibility review.

This release does not establish production readiness for PayFast, Peach or Ozow transactions. Existing iKhokha behavior is preserved; merchant credentials and real acceptance testing remain necessary.
