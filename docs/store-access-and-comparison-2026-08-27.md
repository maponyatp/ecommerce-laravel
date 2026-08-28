# Store access security and comparison — 27 August 2026

## Implemented

- Chat reads, messages, closing and feedback now require the account owner, the server-owned guest session, or an administrator. Knowing a numeric conversation ID or a historical public session UUID is insufficient.
- Guest ownership uses server session values, not client input. Start reuses the current open conversation and generates a new ownership key after closing. No existing chat ownership is reassigned.
- Public chat responses expose only conversation ID/status and the latest 100 message records. Customer/agent account records, email addresses and ownership keys are not serialized.
- Public message GET requests no longer mark messages read as a side effect. Chat routes use private/no-store headers and throttling; start/message/close/rating limits also apply to Livewire requests through the shared service.
- Closed chats reject further messages. Close is idempotent. The widget now performs server-side send, recovery, polling, close and feedback, with locked conversation/message state and escaped content.
- Admin conversation pages have Send reply and Close chat actions. Agent-dashboard methods recheck staff access.
- Management API routes for products, collections and supplier operations now enforce administrator role, staff permission and token scope before entering the controller. Permission checks use the user's current permission team.
- Added the missing Sanctum token table using the installed package's schema. No production API tokens are issued, no existing users receive extra roles/permissions, and the customer token-management UI remains disabled.
- Product comparison now resolves before the product-slug route. Product pages have working Add to Compare/View comparison controls; the comparison supports four products, deduplication, current database prices, category checking, remove/clear, deleted-product filtering and empty state.

## API authorization contract

Both admin/super_admin role and the permission below are required. Catalogue collections intentionally use the same product-management permissions.

| Action | Staff permission | Token ability |
| --- | --- | --- |
| List products/collections | view_any_product | catalog:read |
| Read product/collection | view_product | catalog:read |
| Create product/collection | create_product | catalog:write |
| Edit product/collection or collection membership | update_product | catalog:write |
| Delete product/collection | delete_product | catalog:write |
| Supplier listing/availability | view_any_product | fulfillment:read |
| Supplier tracking | view_any_order | fulfillment:read |
| Supplier order placement | update_order | fulfillment:write |

Sanctum first-party sessions follow its transient-token behavior; role and permission checks still apply. Bearer tokens need the named ability (or a deliberately issued wildcard). No token issuance workflow was enabled by this release.

## Verification

126 tests / 734 assertions passed across the new access-security/comparison tests, existing chat/API tests, and cart/checkout/commerce regressions. Existing API success tests now use authorized staff fixtures; new denial tests cover ordinary customers, missing write scope, revoked permission, real customer bearer tokens and expired staff tokens.

Other coverage includes server-owned guest recovery, cross-account rejection, direct session-UUID rejection, public response privacy, widget send/poll/close/rating, locked state, escaped messages, admin reply, chat rate/length limits and comparison CRUD/maximum/deleted entries.

Production asset build passed with the existing large-JavaScript-chunk warning. The local browser webview did not attach, so desktop/mobile visual validation is not established. This selected suite does not replace a full security assessment, full-suite run or MySQL concurrency/load test.

## Release

Scoped release: 13 runtime files (including migration 000020) plus public/build. Tests, docs, fixtures and secrets are excluded. Existing production source hashes are checked before deployment and final source/build hashes after deployment.

Backup: /var/backups/flowershop/store-access-20260827-1

Archive SHA-256: f2a9f02cba06a21917e6ce27559403434503bf678cefa2630303ef0f1a4dbfa4

Build manifest SHA-256: 52d5018a4b6cc60fe56ac471ec94d8f573b366b8634a36db7422b52c87dd6c95

Deployed successfully after verified database/code backup.

- Migration 000020 completed. Configuration, routes and views cached; queue restart signalled; PHP-FPM reloaded.
- All 13 source/migration hashes and the build manifest matched the tested release.
- Read-only production checks confirmed the unshadowed comparison route, API permission middleware, chat privacy middleware, locked widget properties and token schema. The token table contained zero records; none were issued.
- HTTP 200: /health, homepage, products, comparison, cart, admin login and the new stylesheet.
- Requests accepting JSON returned 401 for the guest agent dashboard and all three management API families. A nonexistent chat ID (0) returned 404 without reading a customer conversation.
- Application is out of maintenance mode; nginx, PHP-FPM and checkout-recovery timer are active.
- Both configured-payment checks remained false. No merchant secrets or payment settings were changed.

## Still open

- Production configuration checks show both iKhokha and Stripe unconfigured. Paid checkout remains unavailable. Merchant credentials and approved settlement/refund testing are required; secrets were not invented or changed.
- DSV account-specific booking, labels and tracking integration remains unimplemented.
- Purchasable variants, variant inventory/reservations and immutable variant order snapshots remain unimplemented; the existing module is draft-only.
- Refunds/credit notes/returns still need a gateway-confirmed workflow. Existing Refund::process bookkeeping is not proof of a payment refund and was not activated or presented as a completed workflow.
- International selling remains ZAR with South African delivery only; no unsupported destinations, tax assumptions or currency conversion were enabled.
- Supplier-order payment validation, broader team-panel permissions, security/load testing, mailbox delivery and backup restoration still require separate review.

Previously exposed guest chat UUIDs are deliberately not accepted as ownership credentials. Guests with only an old UUID and no new server ownership entry must start a fresh chat; their old records are retained for staff. No customer conversations, orders, payments or shipments were created or modified for production testing.
