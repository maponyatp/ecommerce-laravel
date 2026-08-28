# Private customer profiles — 27 August 2026

## Staff workflow

Admin → Commerce → Customers → View history → **Edit internal profile** now supports:

- An internal display name (120 characters).
- Up to ten staff labels (30 characters each; letters/numbers/spaces/hyphens/underscores). Labels are trimmed, lowercased, deduplicated and sorted.
- Private staff notes (4,000 characters).
- A revision history with staff author, South African timestamp, and previous/new values. Audit entries paginate ten at a time.

These are staff-reference annotations, not edits to the customer's login name/email, billing records, legal identity or delivery instructions. Purchase history stays read-only. No marketing consent, account merge, notification, shipment or payment is triggered. The follow-up in `customer-directory-search-2026-08-27.md` adds directory-wide internal-name search, exact-label filtering and row badges.

## Identity and safety

Profiles use a unique SHA-256 key derived from a versioned tuple of order team, identity kind and existing identity value. The kinds are account ID, legacy customer ID, exact guest receipt email, or order ID for unidentified orders. They follow the customer-directory boundaries; accounts and guests sharing an email never merge.

An account's changed receipt email retains its internal profile because its account ID is stable. A changed account/legacy/guest/team link resolves a different profile; existing annotations are not silently moved. Historical annotations remain stored, with no automatic relinking or orphan-cleanup workflow.

Staff must have admin/super-admin role and Order list, view and update permissions to save. Read-only order staff can inspect the profile but cannot edit. Both initial and subsequent renders enforce access; the Livewire profile target is locked against client changes, and the service checks the expected identity again after acquiring locks.

Saves lock the oldest order in the contact group before the selected order/profile, so different order entry points share a creation lock. The unique identity constraint prevents duplicate profiles. A version check rejects stale forms with visible instructions to close/reopen the editor. Empty first saves create nothing; unchanged values do not increment revisions. A profile update and its audit record share a transaction; simulated audit failure rolls back the profile.

Audit history has no edit/delete interface. Clearing current notes preserves previous values in the history, as disclosed in the UI; this is not a data-erasure mechanism. Do not store passwords, card data or unnecessary sensitive information. This is an application audit trail, not tamper-proof storage against database administrators.

Internal fields and audit values are escaped in HTML and are not attached to storefront/order/invoice/packing views. The existing private/no-store/no-referrer/noindex customer route and request throttle remain. No public profile endpoint or export was added.

## Verification

- Combined commerce regression suite: **251 tests / 1,026 assertions passed**.
- Seven checkout/delivery JavaScript checks passed. Production assets built successfully, with the existing approximately 501 kB JavaScript-chunk warning.
- New tests cover server-owned audit authors, no order mutations/notifications, shared guest profiles, stale creation/update rejection, account/guest/case/team boundaries, identity reassignment, normalized no-op saves, clearing with retained history, input limits, audit-failure rollback, read-only permissions, actual repeated Filament action saves, visible validation, locked target tampering, escaped notes, customer confirmation privacy and audit pagination.
- Local browser successfully opened the editor, entered synthetic name/label/notes, saved, and inspected the saved profile and expanded before/after audit entry. Desktop editor layout was visually inspected. Production accounts were not used for this test.
- Local PHP preview initially hit 30-second filesystem/classloader timeouts while tests were running; browser verification succeeded on retry. This does not establish production load performance. No dedicated mobile-editor/accessibility audit or concurrent multi-process MySQL write test was performed.

## Production deployment

Deployed to `https://shop.maponya-tech.com` after verifying the database/code backup at `/var/backups/flowershop/profiles-20260827-1`.

- Migration `2026_08_27_000017_add_private_customer_profiles` created `customer_profiles` and `customer_profile_changes`. No existing contact data was backfilled or edited.
- Six explicit runtime source files and the verified production build were deployed. Secrets, local fixtures, tests, helpers and unrelated dirty-worktree files were excluded. Both existing customer-page files were checksum-checked before replacement.
- Release archive SHA-256: `f5a50cecfab90dea5973d2081e602175a237cc434360653f25d837b488923b17`.
- Build manifest SHA-256: `2c32c36c7b828a29f60edb1ce006df38edc2508efb8053700013b480b940baf3`.
- Migration, configuration/routes/views and read-only schema/route/locked-property checks succeeded. Both new production tables were empty. Application is out of maintenance; nginx, PHP-FPM and the checkout-recovery timer are active.
- All six deployed source checksums and the build manifest match the tested local release. Health/home/catalogue/admin login returned HTTP 200; guest customer directory/profile requests returned 302.

## Remaining scope

This extends the previous read-only directory with internal profile editing; it is not complete CRM or Shopify parity. Consent lifecycle, ownership-verified account linking, contact-address changes, retention/erasure policies and larger-scale performance verification remain separate work. Directory name/label filtering was added in the documented follow-up. Live DSV integration, verified gateway settlement/refunds, credit notes, partial fulfilment, variants/options checkout and wider security/load/restore checks remain in the commerce audit.
