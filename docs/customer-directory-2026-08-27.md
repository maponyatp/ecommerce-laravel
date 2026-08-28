# Customer directory — 27 August 2026

Follow-up: `private-customer-profiles-2026-08-27.md` adds staff-only display names, labels and notes with revision history. Purchase/order ownership remains read-only; the original release details below describe the directory before that extension.

## Delivered

Admin → Commerce → Customers is a central, read-only purchase-contact directory. Orders → Manage now includes a Customer history link. Staff can search receipt emails, filter contact type, inspect orders and currency-separated paid totals, and open related support cases.

This closes the purchase-history discovery gap, **not the entire CRM/customer-management scope**. It does not create/edit customer profiles, infer marketing consent, send messages, export contact lists or merge accounts.

## Identity rules

- Existing `orders.user_id` is the primary account link, even if the receipt email changes.
- With no account link, existing `customer_id` identifies a legacy customer record.
- With neither link, exact nonempty receipt email groups guest orders. Case and whitespace differences remain separate; `HEX` comparisons avoid MySQL case-insensitive/trailing-space equivalence.
- Missing/space-only receipt emails remain separate per order. Each group is also separated by order `team_id`.
- Account, legacy and guest contacts never merge merely because their email strings match. Guest grouping is not verified identity and does not grant storefront access or change the existing customer order-ownership rules.
- Gift recipients and delivery contacts are not buyer profiles. Shipping addresses, gift messages and support-message bodies are not shown in this directory.
- Only contacts with orders are included. Existing customer records or registered users without orders are not presented as purchase contacts.
- Receipt email sample is historical order data, not a claim about the account's current contact details. No historical data is backfilled.

## Access, totals and pagination

Access requires admin/super-admin role plus both Order list and view permissions. Authorization runs on rendering as well as initial page access; a regression test covers permission revocation on a subsequent Livewire render. Order-edit links require update permission. Support queries/links respect the support policy.

The route adds private/no-store, no-referrer and noindex headers, and a request throttle. The page is inside the existing authenticated admin panel. User input is bounded and validated; wildcard characters in email search are treated literally with bound query parameters.

Paid totals are grouped by recorded currency, with missing currency explicitly labelled. Pending/refunded orders are excluded from those totals but remain in history. Values are gross recorded paid-order totals, not accounting net revenue or reconciled lifetime value. No refund, credit-note or exchange-rate calculation is implied.

Directory, order history and cases paginate independently at 25, 15 and 10 rows respectively. Group-level search keeps the full order count when only an older receipt email matches. No bulk contact export is introduced.

## Verification

- Combined commerce suite: **236 tests / 949 assertions passed**.
- After final display wording changes: **16 customer-directory tests / 82 assertions passed** again.
- Seven JavaScript checkout/delivery checks passed; production assets built successfully. Existing approximately 501 kB JavaScript chunk warning remains.
- Tests cover role/permission denial, account/legacy/guest separation, exact guest matching, null identities, team boundaries, changed email, search literals, currency-separated totals, related-case scoping, recipient/message omission, read-only permissions, malformed filters, all three paginators, privacy headers, no notifications/mutations, Livewire rendering and permission revocation.
- Local browser verified directory navigation, email/type search, guest purchase history, separated USD/ZAR totals and visible order/support links. Desktop directory/profile and narrow profile layout inspected. Local previews use an isolated SQLite database and synthetic data, not production records.
- Read-only production MySQL checks successfully executed grouped directory/count/search/type-filter queries. Production had zero purchase contacts, so populated MySQL profile execution and large-dataset performance were not demonstrated. Populated workflow tests used SQLite/local browser fixtures.
- No production customer account, order, shipment, payment or notification was created or modified for testing.

## Deployment

Published to `https://shop.maponya-tech.com/admin/customer-directory`.

- Verified backup: `/var/backups/flowershop/customers-20260827-1` (database and code).
- Release archive SHA-256: `9d4100911911fd85c7775dc41b4665d522597e48eb73aa8820be8da3e19c13a3`.
- Build manifest SHA-256: `fca33b03db04e5743661b11ea96ba30bbbb29d141c152e6528bd228380743d55`.
- Five explicit runtime source files plus `public/build` deployed; no migrations, secrets, tests, helpers or unrelated dirty-worktree files included. Existing order-editor checksum was checked before replacement.
- Config/routes/views rebuilt, queue restart requested, PHP-FPM reloaded. Application out of maintenance; nginx, PHP-FPM and checkout-recovery timer active. All five source hashes and build manifest match local release.
- Production HTTP checks: health, homepage, catalogue, admin login and new theme stylesheet returned 200. Guest directory/profile requests returned 302. Health reported database connected.

## Remaining work

Full customer-profile editing, consent/audit-backed marketing workflows and safe ownership-verified account linking remain separate modules. This release does not solve live DSV integration, verified gateway settlement/refunds, credit notes, partial fulfilment, variants/options checkout, MySQL load/concurrency, broad authorization/security/accessibility audits or backup restore drills. Refer to the broader shop-management gap audit.
