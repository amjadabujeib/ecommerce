# Requirements 1 and 2 — verification record

Date: 2026-11-10. Current branch: `test/requirements-1-2-acceptance`, based on merged main commit `5e06d54`. Initial runtime work was performed on the former `fix/requirements-1-2-verification` branch.

## Current technical acceptance — wrap-up on 2026-11-10

**Requirements 1–2 technical acceptance passes, including the basic GUI requested by the user.** Team participation is excluded from this wrap-up at the user's request. Requirement 3 remains deferred. No push or merge into main was performed; focused commits remain on the acceptance branch.

The following results supersede the pending/gap statements in the historical investigation sections below. Those sections preserve the earlier failures and the evidence used to resolve them.

| Check | Current result |
| --- | --- |
| Build/start | App, web and inventory images built from current source with cached base/native layers and locked dependencies. Final app/web source builds also passed with `--network=none`. Six isolated containers booted: PHP-FPM, Nginx, two PostgreSQL databases, inventory and the recovery scheduler. Migrations, seed/config/route setup and scheduled command execution succeeded. Compose configuration validates; 162 PHP source/test files pass syntax checks and changed documentation links resolve. |
| Full Laravel suite | **88 passed, 400 assertions** via `php artisan test --fail-on-warning --fail-on-notice`, PHP 8.5.12, locked development dependencies, SQLite and the inventory fake. This proves HTTP/orchestration behavior; real database/network checks are recorded separately. |
| Real inventory suite | **15 passed** via `npm test` in the source-built inventory container with a disposable `TEST_DATABASE_URL`; PostgreSQL isolated schemas and real gRPC, including lock/replay/deadline/migration regressions. |
| General HTTP acceptance | `verify-requirements.mjs` passed registration, role escalation refusal, JWT/refresh/logout, ownership/admin enforcement, real PNG/image rejection, category filtering, pagination, authoritative prices, cart/checkout, notifications, status/cancellation, shortages, login throttling, JSON errors and headers. |
| Module boundaries | Source architecture guard passed; `verify-modularity.mjs` passed data snapshots, deletion/history/cart rules, administrator views/events and notification ownership. Presentation check: one and eight orders both use five queries. |
| Last unit | `race-condition.mjs`: ten buyers, **one 201, nine 409s, one persisted order, stock zero**. |
| Stopped inventory | `inventory-outage.mjs`: structured 503 with `Retry-After: 5` in **1050 ms** at a 500 ms test per-call deadline; unknown stock while browsing, no order. Recovery after service IP change in **370 ms**; preserved cart checked out once. |
| Slow inventory | `slow-inventory.mjs`: **1500 ms artificial RPC delay**, structured 503 in **1082 ms**, no order, all stock restored after delayed Reserve/Release handlers settled. Original inventory container restored in `finally`. |
| Local persistence failure | `persistence-failure.php`: real remote stock reserved, deliberate order-line save failure, local transaction rolled back, stock restored and cart retained. |
| Durable recovery | `reservation-recovery.php` in separate processes: committed intent and real remote reservation left behind, inventory stopped, release failed and pending intent survived; inventory restarted, recovery restored stock exactly once. Repeated recovery preserved stock. Laravel tests additionally cover commit ambiguity, new-intent grace period, timeout after remote commit, and checkout persistence failure plus unavailable compensation. |
| Proxy failures | `proxy-errors.mjs` against isolated Nginx without PHP: **413, 502, 403** preserve status and JSON envelope/content type, security headers, and Retry-After on 502. FastCGI application errors are not intercepted. |
| GUI/browser | Headless installed Brave via dependency-free CDP script: admin sign-in, category/product creation and PNG upload/rendering, customer registration/search/category filter/cart/checkout/own orders, notifications/read-all, reload authentication, administrator status change. No runtime/CSP errors; no horizontal overflow at 390 px. The final source-built app/web images passed this browser demo without source mounts or copied files. |
| Persistence | `persistence-restart.mjs`: all six containers stopped and restarted, using disposable **named volumes** for both databases and application storage; **six existing orders, catalog stock and an existing JWT survived**. Nginx starts after PHP so its upstream resolves the current container address. |
| Network budget | User approved up to 100 MiB. A temporary counting CONNECT proxy enforced the cap for dependency downloads. Total **31,284,418 bytes / 29.84 MiB**; no base image pulls, host package installs or frontend packages. |

The GUI is served at `/` and consumes unchanged `/api/v1` interfaces. It uses local JS/CSS and per-tab sessionStorage JWTs. All prices/authorization/ownership decisions remain in the API. The source slide excludes visual-design grading; it does not expressly waive a functional frontend. This corrects the earlier backend-only interpretation.

Recovery commits an intent **before** Reserve, locks it through remote reservation and the local order transaction, and marks it committed with the order. Failures attempt Release using the same UUID. Failed compensation or process death leaves a pending intent. `orders:recover-reservations` locks/checks that intent and a possible committed order before release. The Compose `scheduler` runs it every minute with a five-minute age threshold; it waits for app health after migration startup. Recovery requires eventual access to both systems. No queue broker or requirement-3 infrastructure was introduced.

### Reproduction and limits

Use disposable databases/volumes and test administrator credentials. Never point destructive demos at real shop data. The test stack used port 18080, an isolated `novacart-wrapup` network, independently owned PostgreSQL databases and an application deadline of 500 ms; the committed default remains 2000 ms.

```fish
# In laravel-app after locked development dependencies and local runtime directories exist:
php artisan test --fail-on-warning --fail-on-notice
php tests/Architecture/check-modules.php

# From repository root, with a disposable stack and ADMIN_PASSWORD already set:
env BASE_URL=http://127.0.0.1:18080/api/v1 ADMIN_PASSWORD=$ADMIN_PASSWORD node docs/demo/verify-requirements.mjs
env BASE_URL=http://127.0.0.1:18080/api/v1 ADMIN_PASSWORD=$ADMIN_PASSWORD node docs/demo/verify-modularity.mjs
env BASE_URL=http://127.0.0.1:18080/api/v1 ADMIN_PASSWORD=$ADMIN_PASSWORD node docs/demo/race-condition.mjs
env BASE_URL=http://127.0.0.1:18080/api/v1 ADMIN_PASSWORD=$ADMIN_PASSWORD INVENTORY_CONTAINER=novacart-wrapup-inventory node docs/demo/inventory-outage.mjs
env BASE_URL=http://127.0.0.1:18080/api/v1 ADMIN_PASSWORD=$ADMIN_PASSWORD INVENTORY_CONTAINER=novacart-wrapup-inventory node docs/demo/slow-inventory.mjs
env BASE_URL=http://127.0.0.1:18080/api/v1 ADMIN_PASSWORD=$ADMIN_PASSWORD CONTAINER_PREFIX=novacart-wrapup- node docs/demo/persistence-restart.mjs
```

`gui-browser.mjs` requires an already running disposable Brave/Chromium with `--headless --remote-debugging-port=19223 --user-data-dir=<temporary-directory>`. Use the shop origin as its BASE_URL (without `/api/v1`). It uploads a temporary PNG and leaves fixture accounts/products/orders in the disposable database.

For `reservation-recovery.php`, copy into the app container and execute as `www-data` from `/var/www/html` with APP_ENV=testing: `prepare`, stop inventory, `unavailable`, restart inventory, `recover`. The process-local fixture state contains IDs only, lives in storage, and is removed on successful recovery. Its fixed synthetic inventory ID is for disposable databases only.

These passes are functional acceptance evidence, not an exhaustive proof of all distributed failures or production performance. Source builds reused cached native/base layers; a fully uncached native gRPC build was not downloaded or benchmarked. SQLite feature tests do not prove PostgreSQL locking. The real integration suite covers inventory locking and checkout races. Product administration and cancellation still involve independent database commits without distributed atomicity; checkout recovery does not claim to solve every such operation. Teammate participation/approval coverage is deliberately deferred, and requirement 3 has not begun.

Temporary test containers, network, named volumes, browser profile and budget proxy are removed after the final checks. Locally built images and ignored locked development dependencies may remain for subsequent work; no real services or databases were changed.

## Historical investigation (superseded where noted)

## Scope and environment

The user updated main and authorized isolated container tests. Existing uncommitted specification/source-conversion work was preserved on the new branch. No commit, push, merge, image pull, dependency download, host package installation, or host service change was performed.

The host's `docker` command uses Podman compatibility. Tests used locally cached images with PHP 8.5.12, Laravel v13.39.0, grpc/grpc 1.85.0, jwt-auth v2.9.3, Node.js 22 and PostgreSQL 17. The three PHP library versions were checked against `composer.lock` and matched.

Initial HTTP tests used a PHP development server with four workers and distinct core/inventory databases on one disposable PostgreSQL container. The deployment-path checks were then repeated through cached Nginx and PHP-FPM images using **separate core and inventory PostgreSQL containers**, tmpfs database storage, an isolated network, and a dedicated shared application-storage volume. Current source was copied into the cached PHP image; inventory source and the shared proto were mounted over its cached dependencies. The normal entrypoint successfully migrated, seeded, cached config/routes, and started PHP-FPM.

These runs validate the source with cached runtime dependencies; they do not establish that a fresh Docker build succeeds. The Laravel inventory deadline was shortened to 500 ms for failure tests; the repository default remains 2000 ms.

## Results

| Check | Result and evidence |
| --- | --- |
| Existing inventory baseline | 10 existing tests passed through real gRPC/PostgreSQL |
| Reservation replay regressions | Changed quantities and replaying a rejection after replenishment both failed before the fix and passed afterward |
| PHP inventory fake | Direct execution confirmed rejection replay, changed-payload rejection and no stock addition when releasing rejected attempts |
| Updated inventory suite | **15 tests passed**, including last-unit concurrency, simultaneous identical retries, equivalent duplicate/reordered lines, reserve/release idempotency, early release, deadlines and legacy-schema upgrade/repeated migration |
| Registration and JWT | Public role escalation ignored; missing/invalid tokens rejected; refresh revokes old token; logout revokes refreshed token |
| Expired JWT | Correctly signed valid and expired tokens tested in separate fresh Laravel processes: 200 and 401 respectively |
| Authorization | Foreign customer order read 403; customer admin mutation 403; admin completion/cancellation succeeded |
| Catalog | Real PNG upload accepted and served through Nginx/shared storage; text disguised as PNG rejected; category filtering and pagination metadata passed |
| Checkout | Server-calculated total ignored client amount; stock decreased; shortage returned 409 without an order; cart preserved on failure |
| Notifications/lifecycle | Customer notification present; cancellation restored stock; repeated cancellation did not restore twice; invalid transition returned 422 |
| Security and errors | JSON error envelopes and configured security headers verified on successful and failed HTTP requests; login limit returned 429 after six attempts |
| Required HTTP last-unit proof | **10 buyers: one 201, nine 409s, exactly one persisted order, final stock 0**; repeated through Nginx/PHP-FPM |
| Required stopped-inventory proof | Through Nginx/PHP-FPM: 503 in **1029 ms**, `Retry-After: 5`, unknown stock while browsing, no saved order |
| Recovery after IP change | After channel configuration fix, stock became readable in **305 ms** after restart through Nginx/PHP-FPM; original cart checked out once, without restarting PHP workers |
| Slow inventory | 1500 ms artificial delay with 500 ms per-RPC deadline: checkout 503 in **1024 ms**, no saved order; delayed reservation/release eventually restored inventory |
| Required persistence-failure proof | Real remote reservation reduced stock from 5 to 3; forced order-line save failure produced 500, rolled back the order, restored stock to 5, and preserved the cart; repeated in PHP-FPM container |
| Configuration/static checks | Compose configuration parsed; 135 PHP source/demo files passed syntax checks; Node demo syntax, local Markdown links, and `git diff --check` checked |

Incorrect credentials currently return 422 with validation-style field errors, as asserted by the existing AuthenticationTest. Missing/invalid/expired bearer tokens return 401. The course does not prescribe the wrong-password code; this pass preserves that existing interface.

## Applied changes and why

- `nodejs-inventory/src/inventory.js`: persist rejected outcomes and normalized reservation items, compare replay payloads, serialize replay against release, and prevent releasing a rejected attempt from adding stock.
- `nodejs-inventory/src/db.js`: add stored shortages and the rejected state to existing schemas as well as new ones. Schema changes run transactionally under the migration advisory lock.
- `nodejs-inventory/src/server.js`: mismatched reservation replay returns gRPC `INVALID_ARGUMENT` rather than success or a generic internal failure.
- `laravel-app/tests/Fakes/FakeInventory.php`: match the real service's revised replay/release semantics.
- `proto/inventory/v1/inventory.proto`: clarify replay semantics in comments. RPC names, fields, and wire types are unchanged; message regeneration is unnecessary.
- `laravel-app/app/Modules/Inventory/InventoryServiceProvider.php`: use `force_new` and a local subchannel pool to avoid retaining stale resolver/connection state across PHP requests after inventory changes IP. This trades cross-request connection reuse for recovery; connection setup cost has not been benchmarked. Within a request, the gateway still reuses its client.
- `docs/demo/race-condition.mjs`: dedicated fixture product, full loser/stock/persisted-order assertions, bounded requests, and cleanup in `finally`; it no longer modifies the first catalog product or reports success from winner count alone.
- Added public-HTTP acceptance and outage/recovery demos, plus a standalone real-gRPC persistence-failure demonstration.

The original recovery attempt failed: the service acquired a new IP and passed its health check, fresh DNS resolution saw the new IP, but HTTP workers retained the old destination. Fresh channels alone were insufficient in the experiment; channel plus subchannel isolation passed the recovery checks. Test-server OPcache was disabled during live-edit experiments; deployment checks then ran the final code through normal PHP-FPM/OPcache startup.

## Repeatable checks

Use **disposable databases**, not a shared shop. These demos create accounts/orders/notifications; race/outage demos cancel fixture orders and remove fixture products, but historical records remain until the test databases are discarded. Outage testing stops/restarts the explicitly named inventory container. Persistence testing deliberately fails order saving. These are verification tools, not production endpoints.

From the repository root, with an already provisioned disposable stack:

```fish
env BASE_URL=http://localhost:8080/api/v1 ADMIN_PASSWORD='TEST_ADMIN_PASSWORD' node docs/demo/verify-requirements.mjs
env BASE_URL=http://localhost:8080/api/v1 ADMIN_PASSWORD='TEST_ADMIN_PASSWORD' node docs/demo/race-condition.mjs
env BASE_URL=http://localhost:8080/api/v1 ADMIN_PASSWORD='TEST_ADMIN_PASSWORD' INVENTORY_CONTAINER=TEST_INVENTORY_CONTAINER node docs/demo/inventory-outage.mjs
```

For inventory tests, from `nodejs-inventory/`, with dependencies already present:

```fish
env TEST_DATABASE_URL='postgres://TEST_USER:TEST_PASSWORD@TEST_HOST/TEST_DATABASE' npm test
```

The inventory helper creates/drops unique schemas. The database account therefore needs schema creation permission. The persistence demo runs from `laravel-app/` with `APP_ENV=testing`, dependencies present, and the real disposable inventory connection configured:

```fish
php ../docs/demo/persistence-failure.php
```

It can also be copied into the disposable app container and executed as `www-data` from `/var/www/html`, as in this investigation, to preserve writable log/storage ownership. It requires no PHPUnit or inventory fake.

## Pending checks and limitations

- **Fresh image build:** `docker build --pull=false --network=none --target app ... laravel-app` failed at Composer install because package archives were not cached in that build stage. Networking was disabled, so no packages were downloaded. This is an offline verification blocker, not proof of an application compile error. Rebuild with an authorized dependency cache/network connection before claiming a clean-build pass.
- **Full Laravel PHPUnit suite:** host PHP/Composer and development dependencies are absent; the cached production image has no PHPUnit. The existing feature/unit suites were not run. Real HTTP/kernel checks above do not replace every assertion in those suites.
- **Compensation recovery:** `releaseQuietly()` attempts release but only logs when release also fails. There is no durable retry/reconciliation mechanism for a failed cleanup or process crash. Normal persistence-failure compensation and delayed-call compensation passed; stronger recovery remains a known gap.
- **Module encapsulation:** the follow-up refactor below removes cross-module model/resource access and cycles. Module data ownership and public interfaces are documented in SPEC.md and checked by a standalone source guard.
- **Hosting workflow:** the follow-up audit below confirms main protection and approved PRs. Per-member participation for each requirement still needs evidence.
- Requirement 3 optimizations and performance benchmarks were not implemented.

Accordingly, the required security, inventory race, outage, and normal compensation behaviors have passing integration evidence. The complete requirements 1–2 verification gate remains open for the pending build/PHPUnit/workflow checks; do not label the whole milestone fully verified.

## Recovery, schema compatibility, and cleanup

The inventory schema change preserves existing reservations, but older inventory code does not understand persisted rejected outcomes. Roll back application and schema/data together from a backup, or use a fresh disposable database; do not simply start the old code against the upgraded reservation data. No real database was upgraded in this session.

All named `novacart-verification-*` containers, their temporary network, and the dedicated storage volume are removed after testing. The changes were subsequently organized into nine commits retained on the acceptance branch. No host dotfiles integration is needed.

## Modular-monolith cleanup follow-up — 2026-11-10

The user requested an implementation cleanup after the initial correctness pass. The core remains one Laravel application/database; inventory remains independently deployed. No new core database migration or REST/gRPC wire change was introduced.

### Changes

- Added Products' public `Catalog` facade and readonly `CatalogProduct` values, plus Users' public `Actor` interface and `UserDirectory`. Consumers obtain catalog/profile data without foreign models or resources. UserDirectory retains framework notification delivery behind the identity module's interface, including compatibility with Laravel's notification fake.
- Removed User-to-Orders/Cart, Product-to-OrderItems, Order/Cart-to-User, and OrderItem/CartItem-to-Product ORM relationships. IDs and existing foreign keys remain. Fixtures now set foreign IDs explicitly where a removed relationship previously powered factory `for()`.
- Added an Orders-owned batch presentation module. Customer/catalog enrichment uses public interfaces; catalog titles are transient read-side properties, not persisted order-line attributes.
- Moved integration events/errors into public surfaces. Order events contain readonly `OrderSummary` values and string statuses, without live models. Notifications queries its own inbox by actor ID and receives only public order data.
- Replaced Products' direct Orders query with synchronous `ProductDeleting`/`ProductRemoved` events. Orders guards pending-order references, requests historical retention, and cleans its own carts; Products retains control of deletion and image lifecycle.
- Added `tests/Architecture/check-modules.php`, runnable without PHPUnit/dependencies, and wired it into `composer test:architecture` and `composer test`. It enforces the documented acyclic import graph, public surface access, and ownership/resolution of relationship targets. The initial source guard rejected 34 forbidden references in the original source; the final guard passes on the refactor. It is a source guard rather than a complete semantic analyzer.
- Added `tests/Architecture/verify-presentation.php` and `docs/demo/verify-modularity.mjs` for batch-read and public-HTTP regression checks.

### Verification

All runtime checks used cached images, current source, separate disposable PostgreSQL containers, Nginx and PHP-FPM, with normal route/config caching and OPcache. The test image's old module directory was removed before copying the final module tree, so deleted classes were not available as accidental fallbacks.

| Check | Result |
| --- | --- |
| Public module/relationship guard | Pass; graph is Notifications -> Orders/Users, Orders -> Products/Users/Inventory, Products -> Inventory, Users/Inventory -> no core module |
| General HTTP acceptance demo | Pass: authentication/roles, IDOR, images, filtering/pagination, totals, notifications, cancellation, stock shortages and throttling |
| HTTP concurrency | Pass: 10 buyers, one 201, nine 409s, one persisted order, stock 0 |
| Module integration demo | Pass: checkout profile/catalog shape, pending-order deletion refusal, historical product title after soft deletion, cart removal, administrator order/dashboard profiles, administrator notifications, event payload values and notification ownership/read state |
| Batch presentation | Pass: one and eight orders both require 5 SQL queries including catalog/category/profile reads; transient catalog data does not dirty persisted line attributes |
| Real persistence failure | Pass when run as www-data: remote reservation compensated, local order rolled back, cart preserved |
| Outage/recovery | Pass: stopped inventory returns 503 in 1032 ms; stock visibility recovers in 367 ms and original cart checks out once |
| Final source checks | 145 PHP files passed syntax checks; module guard, demo syntax, document links, Composer JSON and whitespace checks passed |
| Full PHPUnit/fresh build | Still pending for the previously recorded dependency/cache limitations; no packages downloaded |

Repeat the added checks from `laravel-app/` and repository root respectively:

```fish
php tests/Architecture/check-modules.php
php tests/Architecture/verify-presentation.php
```

```fish
env BASE_URL=http://localhost:8080/api/v1 ADMIN_PASSWORD='TEST_ADMIN_PASSWORD' node docs/demo/verify-modularity.mjs
```

The presentation test requires `APP_ENV=testing`, dependencies, and a disposable migrated database, and rolls back its fixtures. When running PHP demos in the app container, use `docker exec --user www-data TEST_APP_CONTAINER php SCRIPT_PATH` from its application working directory. A root CLI run had created a root-owned log file: subsequent PHP-FPM outage logging failed with HTTP 500. The failure reproduced, `stat`/a www-data write check confirmed the permissions, and restoring that **disposable** file to www-data plus rerunning as www-data fixed the harness. No application error-handling change was needed.

Source refactoring and documentation were subsequently committed and are retained on `test/requirements-1-2-acceptance`. Existing failed-compensation durability and hosted-workflow checks remain separate outstanding work. No requirement 3 feature was introduced.

## Historical branch and commit organization

The reviewed work was initially organized into the following local stack. The user later chose one acceptance branch; these temporary local branch names have now been deleted after verifying that their commits are fully reachable from acceptance. The nine progressive commits are preserved. This table describes history, not current branch instructions. No branch was pushed and main was not modified.

| Order | Branch | Base | Focused commits |
| --- | --- | --- | --- |
| 1 | `docs/requirements-and-agent-guidance` | `main` at `5e06d54` | Presentation conversions; specification, acceptance gates and agent guidance |
| 2 | `fix/inventory-reservation-replay` | `docs/requirements-and-agent-guidance` | Reservation payload/rejection replay semantics, schema upgrade, matching fake and regressions |
| 3 | `fix/grpc-inventory-recovery` | `fix/inventory-reservation-replay` | Channel/subchannel isolation after inventory address changes |
| 4 | `refactor/modular-monolith` | `fix/grpc-inventory-recovery` | Public contracts; atomic consumer/ORM migration; architecture enforcement and batch checks |
| 5 | `test/requirements-1-2-acceptance` | `refactor/modular-monolith` | Public acceptance/failure demos; final verification evidence and current docs |

The final branch contains the complete combined result. Intermediate documentation describes its corresponding stage; final verification claims and this record are committed with the acceptance layer. A local stash named "Recovery snapshot before organizing requirements, fixes, architecture and acceptance commits" preserves the original uncommitted tree. Source files are checked against that snapshot after organization; only branch-workflow documentation is added during the split. Runtime results above predate this Git-only reorganization; creating commits does not constitute a new test run.

## Requirements 1–2 completeness audit — 2026-11-10

The user requested a fresh completeness check and consolidation onto `test/requirements-1-2-acceptance`. The working tree started clean. All six other local work branches (including the old infrastructure branch) were checked as ancestors of acceptance before deletion. Only local main and acceptance remain; remote branches were not changed. The acceptance history retains every commit.

### Course scope coverage

| Course obligation | Current assessment |
| --- | --- |
| Users, products/images, orders, inventory and status tracking | Implemented; previous real HTTP acceptance checks passed |
| Four core modules / modular monolith | Implemented; current standalone dependency check passes; independent inventory remains the requirement-2 extraction |
| Versioned REST, filtering, pagination and executable documentation | Implemented; Postman files parse and previous endpoint demonstrations passed |
| JWT, own-order access, admin status changes, login limits and headers | Implemented; previous HTTP/security and expired-token checks passed |
| Polyglot inventory, owned database, shared proto and query/reserve/release | Implemented; previous real gRPC/PostgreSQL suite passed |
| Atomic multi-item reservation, shortage rejection and last-unit proof | Implemented; 15 inventory tests and the one-winner HTTP proof have passing evidence |
| Stopped/slow inventory, bounded responses and normal save-failure compensation | Passing prior integration evidence; combined failures/crashes remain a stronger recovery gap |
| Uniform errors for every interface failure | **Incomplete:** oversized requests rejected by Nginx return HTML rather than the JSON envelope |
| Entire project reproducible from a clean Docker build | **Unverified:** only current-source runs over cached runtime images have passed |
| Full existing Laravel feature/unit suite | **Unverified:** development dependencies/PHPUnit remain unavailable locally |
| Protected main and teammate-reviewed PRs | Main protection and approvals confirmed through read-only GitHub API checks |
| Every member has a PR for each requirement | **Not established:** five approved merged PRs are visible, one authored by each of five members; per-requirement coverage is not documented |

No major core feature from the supplied first two presentations is absent. The error-format edge case is a concrete implementation gap; clean-build/PHPUnit results are missing verification, and contribution coverage is missing process evidence.

### Confirmed proxy error-format gap — R1-06 / R12 slide 6

`laravel-app/docker/nginx/default.conf` limits request bodies to 6 MiB and has no JSON error handler. A cached Nginx container was started with the **current source configuration**, no external network, and a dummy upstream host mapping. A Node.js client sharing that network namespace sent a 7 MiB request body with `Accept: application/json` to `/api/v1/admin/products`.

Observed response: HTTP **413**, `Content-Type: text/html`, default Nginx HTML body. This rejection happens before Laravel or authentication, so `ApiError::reshape()` cannot normalize it. Corrective work should normalize proxy-level API errors (at least the reproduced oversized-upload case), then verify both status/body and relevant headers through Nginx. No proxy/application source fix was applied during this audit. Both temporary containers were removed, and no images or packages were downloaded.

### Recovery gap — R2-07 / R12 slide 12

`CheckoutService::releaseQuietly()` catches a failed release and only logs the reservation UUID. There is no durable recovery job/record/reconciliation path for a second failure or a crash after reserve and before order commit. The normal course demonstration (save fails, warehouse is reachable) passed. The deck does not explicitly prescribe crash recovery or a queue for combined failures, so distinguish this resilience gap from an absent basic release operation. The stronger recovery guarantee described in SPEC.md is not fully implemented.

### Hosting evidence

Read-only GitHub checks against [amjadabujeib/ecommerce](https://github.com/amjadabujeib/ecommerce) returned protected main, one required approving review, and the same main commit as local `5e06d54`. Five merged PRs were inspected; each had an approval:

- [PR 1 — infrastructure](https://github.com/amjadabujeib/ecommerce/pull/1).
- [PR 3 — inventory](https://github.com/amjadabujeib/ecommerce/pull/3).
- [PR 4 — authentication/security](https://github.com/amjadabujeib/ecommerce/pull/4).
- [PR 5 — catalog](https://github.com/amjadabujeib/ecommerce/pull/5).
- [PR 6 — orders/notifications](https://github.com/amjadabujeib/ecommerce/pull/6).

This satisfies the checked protection/review facts. It does not by itself prove each person's contribution for both requirements. Resolve that evidence as a team without fabricating or splitting work solely to inflate PR counts.

### Before moving to requirement 3

1. Fix and verify the reproduced Nginx JSON-error inconsistency.
2. Obtain a clean Docker build/start result and run the complete Laravel suite in an environment with development dependencies. The previous offline build failure is not proof of a compiler defect, but it cannot count as a pass.
3. Decide and document how to recover failed compensation/crash windows, consistent with the intended requirement-2 guarantee.
4. Record legitimate per-member PR coverage for both course requirements.

The earlier runtime passes remain valid historical evidence. This audit reran the architecture source guard and targeted the previously uncovered proxy path; it did not rerun the complete integration suite or claim the pending checks passed. Requirement 3 has not started and no requirement-3 branch was created.
