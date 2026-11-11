# Follow-up review — six new confirmed issues

Reviewed `c9058c8` on `feat/requirement-3-performance`, comparing the full
implementation with `main`. The earlier five findings and the 100-line cart fix
remain resolved; none is counted again here. Two independent standards/specification
reviews supplied source findings; targeted real HTTP/PostgreSQL/gRPC checks below
confirmed all six. No application code was changed during this review.

**All six findings below are open.** Earlier passing checks remain historical
evidence rather than proof that these paths are correct.

## Standards and correctness

### F1 — P1: unchanged editor stock overwrites intervening purchases

Sources: `laravel-app/public/assets/shop.js:312,378–382`,
`laravel-app/app/Modules/Products/Services/ProductService.php:72–77`, and update
validation inherited from `StoreProductRequest`.

The editor snapshots stock and resends it with every metadata update, including
when the administrator changes only the title. The server treats that stale value
as an absolute warehouse update. Reproduced with a new fixture product:

1. Create stock 10 and GET its admin edit snapshot.
2. A customer checks out two units; public live stock becomes 8.
3. Submit the edit snapshot with only its title changed, exactly as the GUI does.
4. HTTP returns 200 and live stock becomes 10 again, despite the active two-unit order.

Required correction: separate stock adjustments from metadata editing or submit
only intentional stock changes, with matching update validation and concurrency
semantics. The existing dedicated stock PATCH can support that separation.

### F2 — P2: checkout and soft deletion can both succeed incompatibly

Sources: `laravel-app/app/Modules/Orders/Services/CheckoutService.php:63,78–79`,
`laravel-app/app/Modules/Products/PublicApi/Catalog.php:23–24`,
`laravel-app/app/Modules/Products/Services/ProductService.php:195–201`, and
`laravel-app/app/Modules/Orders/Listeners/ProtectProductHistory.php:15–21`.

Checkout reads active products without participating in the product-row lock used
by deletion. Reproduced with real transactions:

1. Give a fixture product one completed historical order, forcing future deletion
   to be soft deletion rather than a physical FK-protected removal.
2. Add it to another customer's cart and pause that checkout with a temporary
   BEFORE INSERT orders trigger using pg_sleep(2). Reservation/catalog lookup have
   already happened, but the new pending order is not yet visible.
3. DELETE the product as administrator: 200, deleted_at is set.
4. Resume checkout: 201; SQL confirms a new pending order references the deleted product.

No serial ordering permits both outcomes: checkout first should block deletion
for a pending order; deletion first should make the product unavailable to checkout.
Required correction: validate/lock checkout product snapshots through a Products-owned
interface inside the core transaction, using a consistent lock order with deletion.
The temporary trigger and function were removed in finally and their absence checked.

### F3 — P2: valid Unicode category names overflow generated slug storage

Sources: `laravel-app/app/Modules/Products/Models/Category.php:43–57`,
`laravel-app/app/Modules/Products/Http/Requests/StoreCategoryRequest.php:23`, and
`laravel-app/database/migrations/2026_10_28_000001_create_categories_table.php:14`.

Name validation allows 100 Unicode characters, but the generated transliterated
slug is not bounded to its VARCHAR(120) storage. A 78-character name containing
70 German sharp-s characters plus eight random ASCII characters produced a
148-character slug and HTTP 500. PostgreSQL logged SQLSTATE 22001 for VARCHAR(120).

Required correction: bound slug generation, reserve room for collision suffixes,
and preserve uniqueness. This is not the separate, un-reproduced concurrent slug
allocation race; that hypothesis is excluded from the confirmed count.

### F4 — P2: accepted NUL-containing passwords cause a registration 500

Sources: `laravel-app/app/Modules/Users/Http/Requests/RegisterRequest.php:23`,
`laravel-app/app/Modules/Users/Http/Controllers/AuthController.php:27`.

The declared password rules require only a confirmed string of at least eight
characters. A valid JSON password containing an embedded NUL meets those rules
but bcrypt cannot hash it. Reproduced POST /register with a unique valid username
and email, matching password_confirmation and such a password: HTTP 500. The
application log identified BcryptHasher's RuntimeException, not validation failure.

Required correction: validate password contents/byte constraints for the configured
hasher before hashing and return a 422 field error for unsupported input.

## Specification and integration

### F5 — P1: supported catalog edits break startup seeding and persistence

Sources: `laravel-app/database/seeders/CategorySeeder.php:12–14`,
`laravel-app/database/seeders/ProductSeeder.php:76–78`,
`laravel-app/docker/app/entrypoint.sh:29–33`.

The entrypoint runs all seeders on every app startup. Reproduced through the
supported category API and the exact seed command used by that startup:

1. Rename the seeded Electronics category to Electronics!; HTTP 200, slug remains electronics.
2. Run CategorySeeder inside the disposable app. Its lookup by the old name finds
   no row and it attempts to insert the occupied electronics slug. The command fails
   on categories_slug_unique.
3. Because the entrypoint uses set -e, this failure prevents it from reaching FPM
   on the next startup. The live FPM process was deliberately not restarted to
   reproduce the already-failing seed command. The category name was restored afterwards.

Related evidence from the same automatic-seeding policy: change the seeded Aurora
monitor's price through the admin API, then run ProductSeeder. Its price reverts
to 449.00 because updateOrCreate rewrites existing managed rows. The original fixture
values were restored. This is grouped with the startup finding rather than counted
as a seventh issue.

Required correction: keep bootstrap data creation distinct from normal restarts,
preserve managed edits/deletions, and avoid identifying seed rows solely by mutable
names. Relevant obligations: R1-03 startup, reachable application and persistent data.

### F6 — P2: CORS prevents browser clients from using conditional product reads

Source: `laravel-app/config/cors.php:26–28`. Relevant obligation: R3-06 ETag workflow.

Real OPTIONS /api/v1/products/10001 with an allowed Origin and requested
Access-Control-Request-Headers: if-none-match returned 204 but allowed only
accept, authorization, content-type and x-requested-with. A cross-origin product
GET sent an ETag but exposed only Retry-After and rate-limit headers. Browser
JavaScript therefore cannot read the tag or authorize an explicit conditional GET.
Node/Postman and the same-origin GUI do not enforce this browser policy, explaining
why the existing demonstrations passed.

Required correction: allow If-None-Match and expose ETag, then verify a cross-origin
browser conditional request rather than checking only the same-origin headers.

## Scope, evidence and cleanup

Runtime probes used only the authorized disposable novacart-perf containers,
novacart_perf database and port 18090. The existing app was left running. No new
dependencies/images were downloaded. Fixture users/products/orders remain in that
disposable database; seed category and monitor edits were restored, and SQL
querying pg_proc confirmed the review_deleted_checkout function was removed.
Temporary reproduction code/output were under /tmp/novacart-review-new-issues.*;
this report preserves their steps and measured outcomes without tokens or passwords.

All six are independent of the previously repaired cancellation/cart concurrency,
numeric-total, Redis-deadline and 100-line validation findings. Unproven standby
stall, boot-health-check timing and concurrent slug allocation concerns were not
counted. Findings per axis: four standards/correctness (worst P1), two specification/
integration (worst P1).
