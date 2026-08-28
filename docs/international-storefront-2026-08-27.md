# International-customer storefront improvements — 27 August 2026

## Scope

This pass prepares the South African flower shop for buyers outside South Africa who want delivery to a supported destination. It does not enable worldwide flower shipping, convert prices, create marketplace listings, or certify legal/tax compliance.

## Implemented

- New catalogue/checkout charges are denominated in ZAR, with an ISO currency code displayed across active product, category, collection, wishlist, cart, checkout, order and invoice views. Both active payment adapters use the intended currency; iKhokha rejects a non-ZAR order rather than relabelling its amount.
- Orders capture currency at creation. Historical currency is backfilled only when stored payment transactions agree on one currency. Other historical orders explicitly show “currency not recorded”; no price conversion or guessed historical currency is applied.
- Physical deliveries require recipient name, phone, street address, city, postal code and an explicitly allowed country. Province/region is optional. South Africa is the only configured country; no overseas destination is enabled. The buyer's location is not used as the delivery address.
- Checkout quotes and order creation share one server-side calculation. Prices, shipping, coupon discounts and configured destination tax are recalculated from server data. Posted amounts/currency cannot override them. If an existing quote no longer matches, checkout requires review before payment.
- Destination tax uses the explicit country/address and discounted merchandise amount. No tax rates were added or changed. This is not a complete international VAT/GST engine: digital taxation, tax-inclusive pricing, compound/product-class/shipping taxes and registration obligations still need work and merchant review.
- Price-preview JavaScript shows the server total, rejects stale responses and blocks submission while the quote is invalid. A zero-total order is not blocked merely because no gateway is configured.
- Product metadata now contains the actual display currency, product image and title. Product JSON-LD escapes HTML-sensitive content and omits invented manufacturer identifiers. Private inventory audit logs no longer appear on public product pages.
- Checkout links to published CMS policies with slugs `terms-of-service`, `privacy-policy`, `delivery-policy` and `refund-policy`. Draft or missing policies are not represented as working links. No legal policy text was invented or published.

## Verification

- Backend regression run: 99 tests / 303 assertions passed without failures or risky tests.
- Four Node tests cover price formatting, zero-total checkout, invalid destinations and out-of-order quote responses. These are DOM stubs, not a real-browser run.
- Production asset build passed (existing large JavaScript chunk warning remains).
- New migration and compiled views passed locally. An isolated local catalogue returned HTTP 200.
- Real browser verification was attempted twice, but the in-app browser failed to attach its webview. Visual and full interactive browser verification remain incomplete.
- No live card transaction, DSV shipment, refund, customer account or order was created as part of testing.

## Production release

- Deployed to `https://shop.maponya-tech.com` with a verified database and code backup at `/var/backups/flowershop/market-20260827-1`.
- Release archive SHA-256: `31600f42cb4c71ebd20793a379dc41684f1c6bd03bb35ffc942e95ee9561ac89`.
- Migration `2026_08_27_000011_add_order_currency_and_delivery_address` completed. Configuration, route and view caches rebuilt; queue restart requested and PHP-FPM reloaded. Application returned to live mode.
- HTTP smoke checks: health, homepage, products, customer/admin login, JavaScript and admin stylesheet returned 200. Empty-cart checkout and guest orders/invoices returned 302. A quote POST without CSRF returned 419, and an unsigned iKhokha webhook returned 403, as expected.
- Only whitelisted runtime files and build assets were released. No environment credentials, policies, destination allowlist expansion or live orders were changed. These checks do not establish successful live payment acceptance or browser/mobile usability.

## Before international launch

1. Confirm actual delivery areas, bouquet handling, cut-off times, weekend delivery, packaging and fresh-flower carrier acceptance. DSV API service/account documentation and credentials are still outstanding.
2. Activate and test merchant-approved payment credentials and supported buyer-card countries. Local currency display does not guarantee international card acceptance.
3. Publish merchant-approved delivery, cancellation/refund, privacy and terms pages, including truthful company/contact details. Review taxes with the merchant's adviser.
4. Implement translations/localisation and currency conversion only for explicitly chosen markets. New country activation also needs destination-specific rates/coverage; do not simply add countries to the allowlist.
5. Audit remaining legacy/admin financial reports for mixed-currency aggregation, finish refund/stock-reservation workflows, and complete browser/mobile/accessibility/load verification.
6. Marketplace accounts, Merchant Center submission and indexing/ranking are separate work requiring the merchant's accounts and choices.

## Primary references

- [Stripe supported currencies](https://docs.stripe.com/currencies): presentment currency and minor units; account/payment-method availability must also be checked.
- [Google product structured data](https://developers.google.com/search/docs/appearance/structured-data/product): product information may support richer search results, without guaranteeing inclusion or ranking.
- [Google merchant listing requirements](https://developers.google.com/search/docs/appearance/structured-data/merchant-listing): product offers, currency and image metadata.
