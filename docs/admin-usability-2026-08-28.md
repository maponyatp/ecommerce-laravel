# Admin usability improvements

## Daily workflow

- The overview now shows the next accessible unfinished setup step and a link to **Setup & launch**. The existing `/admin/store-readiness` URL remains valid.
- The setup guide reads saved contact, business/VAT, logo, product, payment and delivery configuration. It cannot approve launch, send email, create payments or book shipments. Technical deployment checks remain separate.
- Each setup action opens the relevant settings tab directly: `?tab=contact`, `business`, `branding`, `homepage`, `search` or `footer`. There is still one central settings form; no duplicate branding/business settings were introduced.
- Dashboard attention cards open the matching **Orders** tab. Tabs: All orders, To prepare, Payment attention, Stock / delivery review, Out for delivery. Dashboard counts and tab queries share the same model scope.
- Order search accepts the displayed `#123` number or customer email. Clear search/filters or select All orders if the current view is empty.
- Order management displays a next-step explanation and plain-language status labels. The fulfilment selector contains the current state and only the next permitted state. Existing server-side role checks, optimistic locking, verified-payment requirements, stock/delivery holds and dispatch-reference validation remain enforced.
- Buyer/invoice details are shown separately from the gift recipient. Issued invoice buyer snapshots take precedence over mutable order billing details; neither is editable from this display.

## Setup still needs merchant input

The guide does not invent the store logo, support contacts, legal business details, VAT decision or merchant credentials. It does not activate DSV or certify tax calculations. Product presence means products have been added, not that every product/option has been commercially verified. Delivery is considered configured for a non-empty digital-only catalogue without requiring a physical shipping method.

## Verification

Regression tests cover queue/filter agreement, incoming deep links, order-number/email search, guide progress, no-write rendering, access restrictions, fulfilment transitions and escaped billing details. Browser testing uses the isolated local SQLite preview and synthetic orders; no real payment, email or shipment is triggered.

- 111 focused regression tests passed, with 661 assertions. Formatting and scoped whitespace checks passed.
- Browser-confirmed: setup guide, direct Business & invoices tab, dashboard-to-preparation queue with matching count, next-step order guidance and restricted fulfilment options. Mobile viewport testing was not performed.
- Deployed 13 whitelisted files; no database migration or compiled storefront asset replacement.
- Archive: `work/admin-usability-20260828.tar.gz`; SHA-256 `a3fc46c81bc792d8e953c6a324252da46584ab4dc96765e10cda9de44c5e2cc2`.
- Verified code/database backup: `/var/backups/flowershop/admin-usability-20260828-1`.
- Production read-only checks confirmed a working database, correct setup links and next step (`contact`), protected fulfilment options, zero orders/invoices/failed jobs, debug disabled, and active web/PHP/recovery services. Merchant inputs and acceptance checks remain outstanding.
