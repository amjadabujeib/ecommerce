# GUI usability follow-up — 2026-11-11

Status: verified in packaged images and deployed to the live local app.

The user's feedback exposed a gap in the earlier acceptance checks: they tested API-backed shopping flows but did not open the product-details action. That action placed the entire description in a notification instead of presenting a navigable product view. Passing those earlier checks did not establish that the interface was easy to use.

## Changes

- Product images, titles and View details links open a dedicated product screen with image, price, availability, quantity, purchase controls and description. Details never use the notification area. Purchase controls precede long descriptions; missing images have a labelled compact fallback.
- Hash routes support browser Back/Forward, product deep links and reloads. Back to results preserves the catalog filters/page and scroll position.
- Category filters use names from the public catalog response, with clear/reset controls and a result count. Customers no longer need category IDs.
- The cart has an item-count badge, product links, quantity updates/removal and a separate order summary with an explicit Place order button.
- Sign-in and registration are separate choices. A guest's Add to cart action resumes after authentication instead of losing the selected product/quantity.
- Administrator overview, products, categories and orders have separate screens. Product creation/editing uses a labelled modal with explicit Save/Cancel/Close controls and inline validation errors.
- Success/error messages are small dismissible notifications; form errors stay with the form. Dark styling, readable controls, focus handling and mobile layouts are retained.

The only API representation extension is additive: catalog responses now include all named `categories` and `currency` alongside paginated `data`/`meta`; detail responses also include `currency`. Paths, JWT/ownership checks, error envelopes, checkout pricing and inventory authority remain unchanged. The category query stays within the Products module. Postman documentation and the race demo cover the additional metadata.

## Verification

- Full Laravel suite: **89 tests, 406 assertions**, including category choices remaining available outside the currently selected filter. Module architecture guard passes.
- Real HTTP acceptance demo passes authentication/roles, ownership, images, catalog, checkout, lifecycle, errors and headers.
- Updated real last-unit demo passes: ten buyers, one 201, nine conflicts, stock zero and one persisted order.
- Expanded browser suite passes actual product navigation, description rendering in the product view, Back/Forward, deep-link reload, quantity selection, cart badge/subtotal updates, checkout, notifications, admin category/product creation and product editing, status changes and guest sign-up resumption.
- Existing delayed-catalog-response regression still passes. Browser tests open a fresh foreground tab, disable stale asset caching, reset the viewport, and wait for the updated server before navigating.
- Desktop and 390 px product pages were visually inspected. Mobile purchase controls remain easy to reach; no horizontal overflow or runtime/CSP errors were found in the browser workflow.

Fixtures and checkout writes are confined to `novacart-usability-check` on port 18084. A read-only browser check on the old live GUI confirmed the reported details-in-notification behavior before deployment. Real user data and credentials are not used in the automated mutation checks.

Initial packaged-image checks exposed harness assumptions: navigation could occur before PHP-FPM was ready, an out-of-stock first card was mistaken for a loading failure, and background tabs deferred lazy images. The harness now waits for HTTP/DOM readiness independently of stock, brings the target tab forward and resets its viewport. The packaged images then passed the complete expanded workflow without source mounts.

## Operational notes

The existing app's configured credentials are preserved in memory when recreating app/scheduler/web. Database/inventory containers and storage/database volumes are retained. Source builds use cached images/dependencies and the bundled gRPC extension, with networking disabled; no package/image downloads are required for this change.

The temporary test deployment and headless browser are removed after final checks. The live application remains at http://localhost:8080/. A hard refresh loads the new UI; product URLs use `/#/products/<id>`.

Live deployment used the existing container environment without printing credentials. App, scheduler and web were recreated with the new images; the original database/inventory container IDs and storage volume were unchanged. Public catalog data before/after deployment matched. A read-only browser check on port 8080 verified the real product page, description, Back to results, named categories and dark theme. Automated order/stock mutations remained on the disposable port-18084 stack.
