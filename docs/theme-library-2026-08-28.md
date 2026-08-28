# Portable theme library and designer handover

## Delivery status

Implemented and tested locally. **Not deployed to production, and no live theme
was activated.** Existing staged refund/security/email work was preserved.
The isolated UI database in `work/delivery-ui.sqlite` now contains the theme
library migration and a Botanical Studio design fixture; it is not release data.

## Merchant workflow

Super administrators use **Online store → Theme library** (`/admin/theme-library`):

1. Export the current design as a ZIP and retain the original as a designer baseline.
2. Give the ZIP to the designer; its `DESIGNER.md` describes all settings and limits.
3. Upload the edited ZIP as an inactive, immutable version.
4. Open the signed, authenticated homepage preview. Review desktop/mobile before activation.
5. Activate explicitly. A previous-design snapshot is created before updating live settings.
6. Activate a previous snapshot to restore it, or export any saved version.

The library records imports, activations, manual settings edits and the responsible
staff account. Existing settings forms and theme activation use a shared revision
lock plus a settings fingerprint; an older form cannot silently overwrite a new
design. A failed activation rolls back database changes and removes only newly
published assets from that failed attempt.

## Designer contract

Read [the package guide](../resources/themes/DESIGNER.md). The versioned format is
`flowershop-theme/v1`: a root `theme.json`, optional guide and raster `assets/`.
It supports the existing homepage sections/content, their ordering/visibility,
colours, typography, logo/hero/social/favicon images, and safe layout tokens:
split/image-left/centered hero, wide/comfortable content, square/soft/rounded corners.

Run `php artisan theme:validate /path/to/theme.zip` to check a package without
importing, publishing or writing assets. Exported packages are validated by the
same importer so an export cannot quietly produce an unsupported upload.

The package approach follows the principle of an explicit theme architecture and
settings contract, as illustrated by [Shopify's theme architecture](https://shopify.dev/docs/storefronts/themes/architecture).
It is this application's format; it does not implement Shopify's Liquid engine.

## Security and compatibility

- Super-admin authorization is rechecked by the server; disabled staff are rejected.
- Uploaded assets remain private until activation; previews/assets require admin access.
- Preview links expire after 30 minutes and are private/no-store/noindex. Forms and
  network actions are blocked by preview CSP. Links navigate to the live store.
- ZIP entry names, duplicates, symlinks, encryption, compressed/uncompressed sizes,
  compression ratios and image dimensions are checked before installation.
- Images are decoded/re-encoded; remote URLs, SVG and executable assets are rejected.
  PHP/Blade/JS/CSS cannot be uploaded as executable theme code.
- Limits: 10 MB compressed, 20 MB decoded assets/uncompressed entries, 32 entries,
  5 MB per asset, 64 KB manifest, 4000px per edge and four megapixels per image.
- Business identity, contacts, SMTP/payment secrets, currency, products, orders,
  menu records, CMS pages and availability are excluded from theme packages.
- Shared logo/colour branding changes affect new documents; old invoice snapshots
  remain intact. Existing imported versions/assets are retained, not overwritten.

These controls draw on [OWASP file-upload guidance](https://cheatsheetseries.owasp.org/cheatsheets/File_Upload_Cheat_Sheet.html),
including extension/content checks and decompressed-size limits. This is not a
security certification, malware scanning service or complete accessibility audit.

## Known boundaries

- Homepage-only private preview; product/cart/checkout links leave the preview.
- Light-background renderer. Dark backgrounds are rejected by package validation.
- No arbitrary HTML/CSS/templates, add-on installer, WordPress/Shopify ZIP support,
  drag-and-drop block builder, multilingual markets or theme marketplace.
- A package is not a full application/database backup or source-code export.
  Custom templates/add-ons require reviewed repository development and deployment.
- Built-in fallback images remain application assets. Unsupported/missing existing
  uploaded images block export with instructions instead of silently disappearing.
- Previous-design snapshots depend on retained asset files. Do not delete branding
  assets manually; restoring a snapshot with missing assets is rejected explicitly.
- Event history is application-level evidence, not a tamper-proof external audit.
- Local tests use SQLite. Before production, confirm MySQL migration/locking and
  multi-worker behavior, storage/backup retention, and merchant acceptance.

## Verification

- Initial workflow suite: 10 tests / 67 assertions passed.
- Broader regression (themes, existing CMS publishing, branded email and readiness):
  **42 tests / 352 assertions passed**.
- Additional action test confirms upload, explicit activation and inline package
  rejection feedback. Its first fixture used a string where Filament expects an
  upload-state array; the fixture was corrected to match the actual form state.
- Final focused theme suite: **17 tests / 103 assertions passed**, recorded in
  `work/theme-library-final.xml`. This overlaps the broader run; do not add the totals.
- Browser verified the theme library controls, authenticated Botanical Studio
  preview, left-image/rounded/editorial design, and a 390px mobile view without
  horizontal overflow. Long headings now permit wrapping. The preview's products
  are synthetic fixtures; no real payment/delivery test was performed.
- Scoped Pint passed; `git diff --check` reported no whitespace errors.

## Release checklist

1. Review only the theme files; do not deploy the entire mixed working tree.
   `AdminNavigation.php` and `routes/web.php` contain earlier, undeployed refund
   references. Build an explicit production patch against the deployed versions.
2. Back up the production database, code and retained theme/branding assets.
3. Confirm PHP ZIP and GD extensions, local private storage, public storage link,
   disk capacity and upload limits at PHP/nginx/Livewire layers.
4. Install the new classes/views/guide and merge the five theme-specific existing-file edits.
   Also include the existing local `PrivateCustomerDirectory` fix that uses
   `$response->headers->set(...)`: the old production middleware's `header()`
   helper is unavailable on Symfony binary/streamed downloads and would break ZIP export.
5. Apply only `2026_08_28_170000_create_theme_library.php` after review; do not
   migrate unrelated pending features. Rebuild views/routes and verify permissions.
6. Export the actual live design, validate it and test an inactive upload/private
   preview. Obtain merchant approval before activating a redesigned live theme.
7. Check unchanged orders/business settings, asset privacy and previous-design
   recovery. Retain the history table on rollback; its migration refuses deletion.

New production files: `StoreTheme`, `ThemeManifest`, `ThemePackageService`,
`ThemeLibraryService`, `ThemeLibraryController`, `ThemeLibrary` page,
`ValidateThemePackage` command, the migration, page/styles views and designer guide.
Existing-file edits: general settings page, admin navigation, web routes, storefront
layout and homepage hero, plus the private-response middleware compatibility fix.
No package installation is required; the added storefront styles are inline.
