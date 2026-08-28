# Fulfilment documents — 27 August 2026

## Delivered scope

- Admin → Commerce → Delivery operations opens a daily delivery list, also available from Orders.
- Date filters use the South African delivery day, not order creation time or UTC midnight. Delivery method and fulfilment-state filters are available.
- Only paid, stock-committed physical orders in eligible fulfilment states appear. The dated list requires a matching confirmed delivery booking. Unpaid, cancelled, review, supplier-managed and unscheduled orders are excluded. Unscheduled eligible orders are counted separately, without inventing a date.
- The screen paginates at 50 rows. The separate complete print view includes every filtered result up to 500; larger sets are rejected with an instruction to filter, never silently truncated.
- Orders → Manage → Packing slip provides a branded, price-free packing checklist with recipient/address/phone, booked window and gift message. Digital items are explicitly not for packing. Dispatched/delivered slips are marked as reprints.
- New checkout items snapshot the server-owned product name and physical/digital type. Later catalogue edits do not change these packing details. Historical data is not backfilled; legacy fallbacks are explicitly labelled for verification. Other order-creation paths are not guaranteed to populate snapshots.
- Documents use the existing centralized brand name/logo. No separate logo upload or duplicate branding configuration was introduced.

## Privacy and boundaries

Both document routes require authentication, an admin/super-admin role and the relevant Order policy permission. Customer ownership and signed URLs cannot bypass staff access. Responses use private/no-store, no-referrer and noindex headers.

Packing slips omit prices, buyer email addresses and private staff notes. The daily list also omits gift messages. Recipient contact details are still personal data: restrict printed copies to the delivery workflow.

Printing does not change inventory, payment, fulfilment or booking status; send notifications; book DSV; allocate a driver; or generate proof of delivery. These are print-friendly HTML pages with browser Print / Save PDF controls, not a server-side PDF engine. Printed information is a point-in-time snapshot: staff must recheck live order status before dispatch. The list is sorted by time/order number, not optimized driving distance.

## Verification

- Combined commerce suite: **220 tests / 867 assertions passed**.
- After the browser-driven heading/count polish, all **17 fulfilment tests / 105 assertions** passed again.
- Seven checkout/delivery-window JavaScript tests passed; production asset build succeeded. The existing approximately 501 kB JavaScript-chunk warning remains.
- Tests cover staff/customer/guest permissions, unsafe-order exclusions, confirmed versus held bookings, digital/physical handling, immutable item snapshots, legacy warnings, South African midnight boundaries, filters, complete multi-page printing, the 500-order limit, privacy headers, central branding, Livewire admin actions and checkout snapshot tampering.
- Local browser: admin sign-in/navigation, Delivery operations, empty/populated date-filtered list, complete print view and packing slip checked. Desktop and 390-pixel-wide packing layouts inspected; browser review led to correcting the wrapped table heading. The local single-process development server experienced timeouts during dashboard polling before document checks succeeded. This is not evidence of production load performance.
- No physical printer, generated PDF pagination, full accessibility audit or MySQL concurrency/load test was performed.

## Production release

Deployed to `https://shop.maponya-tech.com` with migration `2026_08_27_000016_add_order_item_packing_snapshots`.

- Verified database/code backup: `/var/backups/flowershop/fulfillment-20260827-1`.
- Release archive SHA-256: `2dab22878318731fc55b442b562306fc9b15122208baa5be25f8b0bfbee44c01`.
- Build manifest SHA-256: `14cacce5b168fc1d764b0a27cba14cb5a89dc492e7899bde1d2ad4f4f3f62b7a`.
- Only 13 explicit runtime source files and the verified build were included. Tests, helpers, local fixtures, secrets and unrelated dirty-worktree changes were excluded. Existing modified files were compared against production before release.
- Migration, config/route/view caching and read-only production checks succeeded. Application is out of maintenance; nginx, PHP-FPM and the existing checkout-recovery timer are active.
- The configured `/health` endpoint returned HTTP 200 with database connected; home, catalogue, admin login and stylesheet also returned 200. Guest staff-document/admin-page requests returned 302. All 13 deployed source checksums matched the local release. Production confirmed-booking/eligible-packing counts were zero; no production test orders were created.
- No payment credentials, delivery coverage, customer accounts, order statuses or customer communications were changed.

## Remaining commerce gaps

This release closes the basic packing-document/daily-list gap, not all Shopify-style capabilities. Verified live iKhokha settlement/refunds, account-specific DSV integration and coverage, credit notes/returns, partial fulfilment, variants/options checkout, customer-directory ownership, load/concurrency/security/restore testing and international-market policy decisions remain separate work. Refer to `shop-management-gap-audit-2026-08-27.md` for the broader audit.
