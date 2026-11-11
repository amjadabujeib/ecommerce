# Follow-up review — six new confirmed issues

Reviewed `c9058c8` on `feat/requirement-3-performance`, comparing the full
implementation with `main`. The earlier five findings and the 100-line cart fix
remain resolved; none is counted again here. Two independent standards/specification
reviews supplied source findings; targeted real HTTP/PostgreSQL/gRPC checks below
confirmed all six. No application code was changed during this review.

**All six findings below are now resolved and regression-verified; see the remediation evidence at the end.** The original counterexamples at `c9058c8` are preserved below.

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

## Remediation and final evidence

Implementation commits, one per reported area:

- `a631163` — edit forms leave stock disabled unless “Adjust warehouse stock when
  saving” is explicitly enabled. Omitted stock_quantity now preserves warehouse
  availability; intentional API stock changes remain compatible.
- `d2390d5` — Catalog::lockForCheckout returns primary snapshots after acquiring
  product locks in ID order inside the cart/order transaction. The owning Products
  module retains every model/query; deletion uses the same rows.
- `1cb621d` — transliterated slugs are bounded to 120 characters, retaining room
  for unique collision suffixes.
- `841147d` — registration validates NUL and bcrypt's 72-byte limit before hashing;
  errors are 422 field errors. A 72-byte multilingual boundary password works.
- `8f34680` — CORS allows If-None-Match and exposes ETag.
- `64a8782` — transactional bootstrap ledger initializes a fresh catalog once and
  preserves managed edits/deletions on full or individual seed reruns. Migration
  treats existing users/categories/products as installation evidence, including
  an existing shop with an intentionally empty catalog. Nginx discovers runtime
  container DNS and refreshes upstream resolution after IP-changing app restarts.

Verified on the updated source:

- **109 Laravel tests passed, 535 assertions**, plus the module-boundary guard.
- [Real service regression](../performance/verify-follow-up-fixes.mjs) passed with
  real HTTP, gRPC, PostgreSQL and replica/cache configuration. Both lock orders
  were exercised with temporary SQL barriers: checkout-first returns 201 and
  deletion 409; deletion-first returns 200 and checkout 422 with no new order.
- Unicode names that previously failed now create distinct bounded slugs in
  PostgreSQL. Invalid password contents and byte overflow return 422.
- Cross-origin preflight/GET/conditional-GET checks expose the ETag and return an
  empty 304. This was additionally verified in an actual browser below.
- A real app restart preserved the supported category rename, a changed default
  price and live stock; individual CategorySeeder/ProductSeeder runs also preserved
  those changes. Original performance-stack fixture values were restored.
- A separate fresh core database and inventory database/service initialized all
  eight defaults, then preserved a renamed category, renamed/repriced product,
  deleted default product and stock 11 across a real FPM restart and individual
  seeder reruns. Its temporary containers/databases were removed afterwards.
- [Actual browser regression](../demo/follow-up-browser.mjs) passed using the GUI's
  Edit product/Save controls: metadata-only submission omitted stock and retained
  the post-purchase value 8; explicitly enabled adjustment set 12. A second local
  origin read ETag and completed a real CORS-preflighted request with empty 304.
- Existing real security/workflow, ten-buyer last-unit race, earlier five-fix
  regression, and paused-replica/ETag demonstration passed. Last-unit outcome
  remained one 201, nine conflicts, one persisted order and final stock zero.
  The earlier stalled-Redis regression returned HTTP 200 in 608.8 ms on this run.
- Cached offline source builds succeeded: core
  `e51da4a7ebb98404376179230b26be5c3bc3d4c76e02f330154ddecb8826971a`, web
  `961d98e655541ce2608f1c3c764f624886d6521aa1d82872ac325cf562f5e926`.
  Nginx configuration validation passed. No dependencies or images were downloaded.
- Independent standards/specification re-review found no additional actionable
  defect in these fixes. The empty-upgraded-catalog case found during that review
  was corrected and added to the tests before final verification.

### Failures encountered and corrected

The first real restart check found healthy FPM but an unreachable storefront:
Podman had assigned a new app IP while Nginx retained the old upstream. This was
corrected using runtime DNS resolution, not by restarting web inside the test.
The final test restarts only app and verifies recovery through the existing proxy.
A brief unavailable window can remain until the five-second DNS cache expires;
this is not a zero-downtime deployment guarantee.

The isolated browser's initial deny-all external-DNS test flag also blocked the
loopback test origin. Correcting the test allow-list to include 127.0.0.1 allowed
normal GUI verification. The final test uses visible controls and browser fetch,
not calls into private module-scoped JavaScript functions.

### Reproduction, recovery and rollout

Use only the seeded, authorized disposable novacart-perf stack on port 18090:

```fish
env DISPOSABLE_CONFIRM=novacart-perf ADMIN_PASSWORD='DisposableAdminPassword' node docs/performance/verify-follow-up-fixes.mjs
```

The browser check requires a separately launched disposable Chromium/Brave
instance with CDP on localhost:19223; it temporarily serves another loopback
origin on port 18091. No browser packages are downloaded. Its fixture credentials
come from ADMIN_PASSWORD and it creates only disposable test accounts/products.
The isolated browser was closed after the successful run.

The service regression creates SQL delay triggers verify_six_checkout and
verify_six_delete, drops both in finally, and restores seed fixture edits in
finally. If killed before cleanup, remove those triggers/functions in the disposable
core database and restore the fixture values before retrying. Do not execute these
fault-injection checks on the live shop. Dispose only this test project's volumes.

The normal app entrypoint applies bootstrap_state migration before seeding. Preserve
this table in backups and never clear its completed marker on a managed catalog;
doing so deliberately permits bootstrap work again. On fresh-install failure, the
pending marker permits retry and catalog writes roll back together. Avoid manually
running partial seeders as an initialization workflow; use the full DatabaseSeeder.
Before rolling back to older seeding code, back up the database and account for the
old destructive restart behavior; removing the ledger does not preserve these fixes.
Only the disposable deployment was updated. Historical load measurements remain
unchanged; no new performance benchmark is attributed to these correctness fixes.
Existing dependency advisories were outside this six-finding task and remain
unassessed. No absence-of-all-bugs guarantee is claimed.
