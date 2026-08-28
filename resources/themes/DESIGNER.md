# Flower Shop theme developer kit — v1

This ZIP is a portable design package for this application, not a WordPress,
Shopify, WooCommerce or PHP plugin. Keep a copy of the original export in Git.
Never include credentials, customer records or private business documents.

## Workflow

1. Super administrator: Online store → Theme library → Export current design.
2. Unzip locally. Keep `theme.json` at the ZIP root, not inside another folder.
3. Edit the name, version, author, settings and design tokens. Save UTF-8 JSON.
4. Replace images in `assets/`, retaining or updating the matching image paths.
5. Zip `theme.json`, optional `DESIGNER.md` and `assets/` only. Upload the ZIP.
6. Open the private, signed 30-minute homepage preview. Check mobile and desktop.
7. Explicitly activate the version. The previous live design is saved first.
8. To revert, activate its saved previous-design version. Export any version.

Uploads do not publish. A preview requires a signed-in super administrator.
Developers can run `php artisan theme:validate /path/to/theme.zip` in a configured
development checkout before handing over the ZIP. Validation makes no database
or asset changes and returns a nonzero exit code for an invalid package.
Preview forms and network actions are disabled; navigation links leave the
preview for the live store. Preview is currently homepage-only. Theme versions
are immutable; upload a new version to revise one. Direct Store design & settings
edits change the current design immediately and save a previous-design snapshot.
Settings changes invalidate open activation/settings forms; reload if prompted.

## Package structure

    theme.json
    DESIGNER.md            optional; plain documentation only
    assets/
        logo.png
        hero.jpg

`theme.json` must contain exactly these root keys:

    {
      "schema": "flowershop-theme/v1",
      "name": "Designer theme",
      "version": "1.0.0",
      "author": "Your studio",
      "settings": { "...": "keep all fields from the export" },
      "design": {
        "hero_layout": "image-left",
        "content_width": "comfortable",
        "corner_style": "soft"
      }
    }

The settings example is illustrative: do not literally use the `...` field.
Start from an actual export; it contains every required setting and default.
Unknown keys and unknown schema versions are rejected, never silently ignored.

## Settings contract

- `store_primary_color`, `store_background_color`: six-digit hex, e.g. #14634a.
  The renderer is a light theme. Use a light page background with dark text.
  Primary buttons/footer choose black or white foreground automatically.
- `store_font_style`: modern, editorial or classic (system font stacks).
- `announcement_text`: required, max 255 characters; `show_announcement`: boolean.
- `hero_eyebrow`: null or max 100; `hero_title`: required max 255;
  `hero_description`: null or max 1000.
- `hero_primary_label`: required max 80; `hero_primary_url`: required max 1000.
- `hero_secondary_label`: null or max 80; `hero_secondary_url`: null or max 1000.
- `featured_categories_heading`: required max 120;
  `featured_categories_subheading`: null or max 255.
- `products_heading`: required max 120; `products_link_label`: required max 80.
- `promo_eyebrow`: null or max 100; `promo_title`: null or max 255;
  `promo_description`: null or max 1000; `promo_button_label`: null or max 80;
  `promo_button_url`: null or max 1000.
- `footer_copyright`: required max 500.
- `homepage_category_limit`: integer 1–12; `homepage_product_limit`: integer 1–24.
- `homepage_sections`: exactly four objects, each with `section` and boolean
  `enabled`; include hero, categories, products and promotion exactly once.
  Array order determines homepage order. Hidden sections retain their content.
- `site_logo_path`, `hero_image_path`, `seo_share_image_path`, `favicon_path`:
  null or a referenced `assets/filename.png`, `.jpg`, `.jpeg` or `.webp`.
  Use PNG/JPEG logos for compatibility with branded emails and invoice snapshots.

Text is plain text, not HTML, Blade or Markdown. URLs accept `/local-path`,
`#anchor` or HTTPS URLs without credentials. Scripts, protocol-relative URLs,
backslashes and control characters are rejected. URL syntax validation does not
prove a destination exists; check all links during acceptance testing.

Design enums: hero_layout = split | image-left | centered;
content_width = wide (1280px) | comfortable (1120px);
corner_style = square | soft (12px) | rounded (24px).
Mobile layouts stack automatically; motion is reduced when requested by the OS.

## Upload limits and safety

ZIP ≤10 MB compressed, ≤20 MB uncompressed, ≤32 entries; each file ≤5 MB;
theme.json ≤64 KB. Images: max 4000px per edge and 4 million pixels total.
Images are decoded and re-encoded, stripping embedded metadata; original byte
identity is not preserved. ZIP traversal, duplicate names, symbolic links,
encrypted entries, extreme compression, remote images, SVG and executable code
are rejected. ZIP contents are not extracted into application directories.
Uploaded images remain private until activation. Previous versions/assets are
retained for recovery; agree a retention policy before removing them manually.

Exports include only supported uploaded theme images. Built-in placeholder/hero
images remain application fallbacks. An unsupported or missing existing image
blocks export with a message; it is not silently dropped.

## Boundaries and custom development

Theme packages do not change product data, orders, menus, CMS page records, legal
identity, SMTP, payment credentials, currency, tax or store availability. The
imported logo/colours affect shared branding and future invoices; previously
issued invoice snapshots remain unchanged. A theme ZIP is not a database backup.

Arbitrary HTML/CSS/JavaScript, Blade templates and add-on modules are not loaded
through this portal. For custom templates/components or a new design token,
work in the application repository: resources/views/layouts/app.blade.php,
resources/views/home/sections/, resources/views/themes/styles.blade.php and
app/Support/ThemeManifest.php. Add reviewed validation, responsive/accessibility
tests and an explicit code deployment. Version the schema for breaking changes.

Acceptance checklist: keyboard focus/navigation, headings and image text,
contrast, 320/390/768/1280px layouts, 200% text zoom, long translations, links,
stock/price visibility, checkout regression and private preview access. These
are design requirements, not a claim of WCAG certification or platform parity.
