# Customer directory search and labels — 27 August 2026

## Staff workflow

Admin → Commerce → Customers now supports:

- Searching receipt emails **or** saved internal display names.
- Filtering by one exact staff label, combined with contact type and search text.
- Viewing saved internal names and label badges in directory rows.
- Clearing filters, complete match counts and pagination across all matching contacts.

Labels are trimmed and lowercased; `orchids` matches the whole label, not `rare orchids`. Search treats `%`, `_` and `!` literally. Only purchase contacts with existing orders appear. Staff notes are neither displayed nor searched in the directory; they remain in the permission-protected profile and its audit history.

## Identity, migration and privacy

Migration `2026_08_27_000018_add_customer_directory_lookup` adds private lookup metadata on existing customer profiles. It joins grouped order histories to profiles by order team, identity kind and exact identity bytes. Accounts, legacy customers, guest emails and unidentified orders remain separate. Case and trailing spaces do not merge guest identities, including with MySQL case-insensitive collation.

The original v1 SHA-256 identity key remains authoritative for profile saves. The lookup metadata also stores the underlying identity value, including the exact guest receipt email, in the private profile table; it is not encrypted or hash-only storage. No new public endpoint or export was added. Existing no-store/no-referrer/noindex headers, role/permission checks, locked profile targets, stale-edit protection and audit history remain.

The migration populates metadata only where an existing profile's v1 key matches an existing order identity. It does not create profiles or change names, labels, notes, versions, timestamps or audit history. Unmatched historical profiles remain unlinked. New changed profile saves populate server-owned metadata. Rolling back this migration removes only lookup columns/index; deploy the previous code together with rollback because the new query requires those columns.

Name/email matching uses OR; label/type constraints use AND. Filtering happens after order grouping, preserving full purchase counts even when an account's receipt email changed. No account ownership, consent, order, payment, shipment or notification changes result from searching.

## Verification

- Combined commerce regression suite: **261 tests / 1,073 assertions passed** (not the entire repository suite).
- Seven checkout/delivery JavaScript checks passed. Explicit PHP formatting checks and production asset build passed; the existing approximately 501 kB JavaScript-chunk warning remains.
- Ten new tests cover combined filters, whole-label matching/clearing, exact identity/team boundaries, changed email counts, identity reassignment, literal search, malformed labels, all-page filtering, migration preservation, escaping and read-only privacy.
- Eight synthetic MySQL checks passed using connection-local temporary tables, case-insensitive collation and `ONLY_FULL_GROUP_BY`: grouped counts, exact joins, preserved order counts, literal name search, whole-label matching, combined type filtering, private-note exclusion and paginator totals. No real production rows were read or written by that isolated check. An initial temporary-table `LIKE` setup failed before inserts; explicit temporary schemas corrected it.
- Browser testing used an isolated local SQLite database and synthetic records. Verified migrated profile name/label display, combined name/uppercase-label/type filtering, full two-order count, no-results state, clear filters restoring both groups, private-note omission and profile navigation. Desktop layout inspected. No production account was used and no email was sent.
- Large-dataset performance, mobile/accessibility coverage and concurrent MySQL profile writes remain unverified. The lookup index covers team/kind; byte-exact identity comparison and grouped order scans require measurement at larger scale.

## Production release

Live at `https://shop.maponya-tech.com/admin/customer-directory`.

- Verified database/code backup: `/var/backups/flowershop/customer-search-20260827-1`.
- Six explicit runtime source files plus the production build were deployed. Five existing source files and the manifest passed pre-replacement checksum checks. Secrets, local fixtures, tests, helpers and unrelated changes were excluded.
- Release archive SHA-256: `7298a182b57d8ce969544e9164ac3e999191c0d79c90fec40dd764e8bb1e6a8f`.
- Build manifest SHA-256: `2c32c36c7b828a29f60edb1ce006df38edc2508efb8053700013b480b940baf3`.
- Migration, config/routes/views rebuild and read-only schema/query/pagination/privacy/locked-target checks passed. Production had zero purchase-contact groups, profiles and profile changes, so no existing production records needed backfill.
- All six deployed source hashes and the build manifest match the tested local release. Application is out of maintenance; nginx, PHP-FPM and checkout-recovery timer are active.
- Health, homepage, catalogue, admin login and theme stylesheet returned HTTP 200. Guest directory/profile/filtered-directory requests returned 302. Health reported database connected.

## Remaining scope

This closes directory-wide internal-name search and staff-label filtering, not complete CRM or Shopify parity. Consent lifecycle, ownership-verified account linking, contact-address management, retention/erasure policies and larger-scale performance remain separate work. Live DSV credentials/account-specific verification, gateway settlement/refunds, credit notes/returns, partial fulfilment, variants/options checkout and broader security/load/restore checks remain open in the shop-management audit.
