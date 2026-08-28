# Email and South African invoice branding

## Delivered

- Central identity: General Settings → Brand & theme sets the name/logo/primary colour used by outgoing Laravel mail. Store details supplies monitored support contacts. The authenticated SMTP sender address is unchanged; its display name uses the store name. Explicit enquiry reply-to addresses are preserved.
- Shared HTML and text layouts cover customer/admin password reset, verification, all nine application notification classes, invoice email, contact enquiries and the optional team invitation template. Team invitations remain disabled; template coverage is not certification of that workflow.
- Repaired missing invoice and optional invitation templates. Invoice emails contain private 30-day signed links; they do not attach PDFs or expose public invoice URLs. Customers can print/save from the protected invoice page.
- Self-contained invoice styles, A4 print rules, clear seller/buyer/delivery blocks, registration references, item/SKU/quantity/unit prices, discounts, shipping, value before VAT, charged tax and total. ZAR and South African issue dates are explicit.
- New checkout billing fields capture the buyer separately from a flower/gift recipient. VAT vendors require buyer name/address; a buyer declaring South African VAT registration must supply a 10-digit VAT number. Format checks do not verify registration with SARS.
- New orders snapshot seller identity, VAT status and branding. Issued invoices retain those values. Supported logo bytes are embedded in invoice snapshots; later logo replacements cannot alter them. Existing invoice snapshots are not rewritten or retroactively labelled VAT invoices.
- PNG/JPEG/GIF uploaded to the public branding directory are supported for email. Invoice embedding is capped at 2 MB. Missing, oversized invoice logos or incompatible SVG/WebP logos fall back to the store name. Arbitrary remote logo URLs/path traversal are not accepted.
- Known seeded example-domain contacts and the original sample UK address/phone are suppressed from these new documents. The contact form refuses to send to a missing/placeholder support address.

## South African document requirements and limits

SARS requires supplier identity/address/VAT number, a serial number and issue date, supply descriptions, quantities and a clear value/tax/total breakdown. Full invoices also require recipient identity/address and a VAT number when the recipient is a vendor. Full tax invoices apply above R5,000; this implementation gathers full buyer details for all registered-vendor checkouts. SARS specifies issue within 21 days of supply. Sources: [SARS guidance](https://www.sars.gov.za/businesses-and-employers/government/tax-invoices/) and [SARS invoice checklist](https://www.sars.gov.za/wp-content/uploads/Docs/Government/Tax-Invoice-Checklist-Version-2-29032016.pdf).

The Tax Invoice heading is conditional on recorded registered-vendor status and required document fields. Unconfirmed, incomplete or inconsistent documents show review warnings. Foreign-currency documents are not automatically converted to rand. Non-vendor documents never claim VAT where none was charged. A tax amount charged under non-vendor status is visibly flagged.

**This is not tax-engine certification.** No rates, prices or historical charges were changed. The existing engine needs merchant/accountant review for VAT-inclusive advertised prices, delivery/digital VAT, zero-rated/exempt supplies, item tax classifications, issue timing, credit notes and record retention. Descriptions must identify second-hand goods where applicable. Do not activate VAT-vendor status as a substitute for that review. Tax amounts on documents are the amounts actually recorded, not recalculated amounts.

## Live merchant inputs still needed

At inspection, the live store was named FLORA, with no uploaded logo, no legal seller name/address, no VAT number, and seeded example support contacts. No merchant identity, address, number or logo was invented.

1. Store details: set the real monitored support email, phone and address.
2. Brand & theme: upload the approved PNG/JPEG logo (preferably below 2 MB), choose the primary colour.
3. Business & invoices: enter legal seller name, business address, company registration where applicable, confirmed VAT status and issued VAT number if registered. Optional invoice footer note is supported.
4. Obtain accountant approval of actual tax/pricing rules before commercial VAT invoicing. Complete a merchant-approved mail delivery and billing acceptance test; no real emails or purchases were made during this change.

The launch checklist now blocks missing support email and unconfirmed VAT status as well as existing merchant/payment checks.

## Verification and release

- 132 focused regression tests passed (799 assertions), including HTML/text notification branding, sender/reply-to handling, invoice integrity, billing validation, checkout, variants, cart pricing, digital fulfilment and recovery. PHP formatting checks passed.
- Browser-reviewed synthetic email and invoice layouts. Invoice styling was made self-contained after visual testing found an external stylesheet dependency. No actual PDF file, mobile viewport or third-party email client was certified.
- Deployed 27 whitelisted application/template/migration files. No storefront build replacement, credentials, user accounts or test fixtures were deployed. File hashes were checked before and after release.
- Release archive: `work/mail-branding-20260828.tar.gz`; SHA-256 `5c1bb4d1b3249ff5ebce8add4a9a396409a4800e848bcec4aa7375867cc7053b`.
- Verified code/database backup: `/var/backups/flowershop/mail-branding-20260828-1`.
- Production migrations completed; HTML/text mail, invoice view and invoice email rendered without sending mail. Home, catalogue, admin login and password-reset pages returned HTTP 200. Nginx, PHP-FPM and the recovery timer were active; recorded production errors remained 1245, with no new entries during the checks. Orders/invoices/failed jobs remained zero.
- Four configuration blockers remain: real support email, seller identity/address, VAT decision, and payment credentials. These are not resolved by branding.
