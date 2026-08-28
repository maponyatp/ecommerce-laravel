# Saved payment reference safety release

## Changes

- Existing GET/HEAD edit URLs only display the edit form. Query parameters cannot update a saved reference. Writes require the existing CSRF-protected POST/DELETE routes.
- Customer ownership is checked for reading, editing, deleting and selecting defaults. All reference mutations serialize on the owning user row in a database transaction, including first-reference creation. Successful writes normalize duplicate defaults for that owner only.
- Browser forms now have real list/edit screens, validation feedback, redirects, masked references, explicit default buttons and responsive styles. Blank replacement values keep the existing reference. Validation failures do not flash reference/card-like input to the session.
- Reference syntax is checked, but this is not processor ownership verification or card tokenization. These saved references do not enable a gateway or charge money.
- Legacy generic payment and PayPal entry points return explicit unavailable results. The previously undefined PayPal method no longer crashes. This does not implement PayPal payments, subscriptions or automatic refunds.
- The existing local `PrivateCustomerDirectory` compatibility fix is now deployed, using response headers compatible with ordinary, streamed and binary responses.

## Verification and deployment

- Final regression: **55 tests, 381 assertions passed**, including saved-reference security, CSRF, ownership, default selection, masking, validation, legacy payment handling, existing admin access, provider settings and iKhokha callbacks.
- An initial test compared an unrefreshed Eloquent model with a database-loaded model and failed on types/column ordering. It now compares database snapshots before and after; the final read-only test passes.
- Testing used the isolated Maponya source checkout and its installed dependencies. A temporary dependency-reuse attempt incorrectly resolved the original conflicted test class; that helper was removed. The final suite used the isolated checkout's own autoloader.
- Eight application files deployed; no route changes, migrations, credential changes or transactions initiated. Archive SHA256: `49cac8a8ae601793f9ff8f9fc7578faf72304627acab8ee258bac4695e9ba1a8`.
- SQL/code backup verified at `/var/backups/flowershop/saved-payments-20260828-1`.
- Production read-only verification rendered both forms with a synthetic in-memory reference, confirmed masking/private download headers and disabled legacy payments, and verified unchanged payment-method/integration fingerprints and order/payment counts. No synthetic customer/payment record was inserted.
- The first CLI render diagnostic lacked the errors bag normally supplied by HTTP middleware and logged one error. Its exception handler was also being replaced during Laravel bootstrap. The diagnostic now supplies the HTTP view context and reinstalls a nonzero-exit exception handler after bootstrap. Final verification passed; error count remained 1251 afterward.
- Home, catalogue, cart and admin login returned 200. Guest saved-payment and integration pages returned 302. Health reported an available database, and failed jobs remained zero.

## Boundaries

The original checkout's 81 merge conflicts are preserved, not resolved or deployed. The old upstream remote remains removed, and publication targets Maponya's review branch only. No full browser/accessibility audit or production-like concurrent MySQL load test was performed in this release.

PayFast, Peach and Ozow checkout integrations, DSV automation, merchant configuration/acceptance, and the previously documented undeployed modules remain separate work. Passing these regressions does not establish overall commercial readiness.
