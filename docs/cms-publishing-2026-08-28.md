# CMS page publishing — 28 August 2026

## Merchant workflow

Open **Online store → Pages** at [the admin page manager](https://shop.maponya-tech.com/admin/pages).

1. Create a page with a title, URL slug, content, optional metadata and a featured image. **Create draft** does not publish it.
2. Existing pages use **Save draft**. The storefront continues to show the previously published version.
3. Select **Preview saved draft**. Links expire after 30 minutes and require a permitted staff login. Preview text is private, not indexed and not publicly cached.
4. Select **Publish saved draft** and confirm. Only the last saved draft is published; save any unsaved form changes first.
5. Use the revision history to inspect earlier versions. **Restore revision as draft** copies a selected version into a new draft; preview and publish it separately.
6. **Unpublish page** hides the CMS page without deleting its history. Remove unwanted menu links separately. Built-in about/contact/shop pages return when their CMS replacements are unpublished.

Existing slugs are locked to protect links. New slugs use lower-case words separated by hyphens. Reserved store-feature URLs are rejected. The canonical page URL is `/pages/{slug}`; existing top-level page routes continue working. There is no URL-renaming/redirect manager yet.

If another editor has changed a page, save/publish/restore is rejected with a reload instruction. Record the unsaved work before reloading. The system never automatically merges competing drafts.

## Security and content

- Page-specific policies allow existing super-admins to manage pages without enabling a global permission bypass. Other staff need the relevant page permissions, including separate `publish_page` authority; ordinary update permission does not grant publication.
- Deletion is disabled in the page manager and page policy. History records cannot be updated or deleted through the revision model. This is application-level protection, not a tamper-proof database ledger.
- Rich HTML is sanitized at save and rendering. Scripts, forms, unsafe links, arbitrary styling and embeds are removed. Supported headings, lists, links, images, blockquotes and tables have explicit storefront typography.
- Featured images accept raster formats up to 2 MB. **Uploaded images are public assets even when the text remains a draft. Do not upload confidential media.**
- Draft data is excluded from normal page serialization. Private previews require both a signed URL and staff authorization.
- Legacy page content is preserved as revision zero on its first managed edit. Unknown historical authors are not invented.

## Verification

- Broad CMS/admin/navigation/security/inventory regression: **86 tests, 543 assertions passed**.
- Final CMS/controller/sitemap regression after toolbar and typography refinements: **22 tests, 152 assertions passed**. This overlaps the broad suite; totals are not independent.
- Tests cover draft/live separation, protected/expired previews, stale-editor checks, publication permission, transaction rollback, restore-as-draft, HTML/metadata safety, immutable history, Filament actions and built-in page fallback.
- The browser skill was used with an isolated SQLite database and synthetic flower-care page. The editor, signed storefront preview and explicit publication confirmation were inspected; publishing created revision 4 and changed the status to Live. A visual review caught missing rich-text typography, which was corrected and rechecked. No mobile or full accessibility certification is claimed.
- No live page, stock, customer, order, payment, email or shipment was created or modified during verification.

## Release record

- Fourteen application files, one additive migration, no compiled asset replacement.
- Archive: `work/cms-publishing-20260828.tar.gz`; SHA-256 `a6efe9437aabfd123ba810374b7c9bab5829eb12a7c528b4207664798c15b675`.
- Verified SQL/code backup: `/var/backups/flowershop/cms-publishing-20260828-1`.
- All eight pre-existing target file hashes were checked against production. The Page model differed from the local baseline only in formatting; no semantic production changes were overwritten.
- Migration `2026_08_28_120000_add_cms_publishing_workflow` adds draft/version/publication fields and revision storage. Only this migration was run. Existing page live-column fingerprints matched before and after.
- The first post-deployment checker reached the protected setup checklist without a current authenticated staff identity. Maintenance mode correctly remained enabled. The helper was corrected to use the existing staff identity for read-only checks; it now reports exception classes and locations. This was a diagnostic setup failure, not a failed migration or storefront runtime error.
- Subsequent verification passed: both existing super-admins can manage/publish CMS pages, deletion remains denied, MySQL JSON draft-title queries execute, all fourteen deployed hashes match, and nginx/PHP-FPM/recovery timer are active. The store was returned online.
- Public home/products/cart/about/contact/sitemap/admin-login checks returned HTTP 200. Guest requests to page management, page creation and private preview redirected to authentication (302). The original global Shield configuration hash is unchanged. The production error count remains **1247**, unchanged from before this release.
- An initial health probe used the unregistered `/up` URL and correctly returned 404; this application defines `/health` instead. The final `/health` response reports `status: ok` and `db: connected`.
- Production currently has zero CMS pages, products, orders, invoices and failed jobs; debug is disabled. The merchant setup guide is still **0/6**, with real contact details next. No synthetic local records were deployed.
- The migration deliberately refuses a destructive rollback. A rollback must be reviewed against the preserved SQL/code backup, especially if staff have created new revisions after release; do not blindly restore a database over newer business data.

## Remaining scope

This completes a **CMS page lifecycle**, not a complete theme-builder or platform-parity project. Homepage/global settings and menus still use their existing immediate-save workflow. Reusable blocks, full responsive acceptance, manual/draft orders, partial shipments, provider-confirmed refunds/credit notes, DSV operational acceptance and international commerce remain open. Real merchant information, catalogue and provider credentials/acceptance are still required before launch.
