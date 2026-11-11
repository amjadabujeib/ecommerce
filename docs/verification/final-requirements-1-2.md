# Final requirements 1–2 acceptance — 2026-11-11

**Result: PASS for all 18 technical acceptance criteria in SPEC.md, plus the agreed customer/admin GUI.** Team participation is excluded at the user's request; this is not a sign-off on per-member PR coverage or the final course grade. Requirement 3 remains deferred.

The subsequent [GUI usability follow-up](gui-usability.md) addresses user feedback the original flow checks missed, and records the updated 89-test suite and product-navigation checks. The counts below describe the earlier audit.

The audit compared the current checkout with [the original slide conversion](../requirements/requirements-1-2.md), [SPEC.md](../../SPEC.md), actual routes and implementation, and a fresh round of executable checks. It follows commit `04df4a1` and includes the final GUI/documentation/demo fixes described below. Earlier investigations are preserved in [the historical verification record](requirements-1-2.md).

## Requirement 1 — API design and security

| ID | Obligation | Final evidence | Result |
| --- | --- | --- | --- |
| R1-01 | Registration, products/images, orders, inventory and status tracking | Real HTTP acceptance and browser workflows exercised registration, PNG upload/rendering, stock administration, checkout, order visibility and administrator status updates. | Pass |
| R1-02 | Users, Products, Orders, Notifications modules | One Laravel application/core database; in-process public interfaces and snapshot events. Architecture guard passes the acyclic dependency graph and module-owned ORM relationships. Inventory is an adapter plus an independent Node service. | Pass |
| R1-03 | Complete project in separate Docker containers | App, web and inventory images built from source with `--pull=never --network=none`, using cached bases/dependencies. A new six-service Compose project started with fresh named volumes, migrations, seed, config/routes and health checks. App and scheduler share the core image. Full restart retained orders, stock, JWT and an uploaded image. | Pass |
| R1-04 | Add product/image, list, filter by category, paginate | Laravel tests and real HTTP checks cover MIME/size validation, category filtering and pagination. Browser checks confirm actual PNG rendering and filtered results, including a delayed-response regression. | Pass |
| R1-05 | Create/display orders and change status | Real checkout persisted an order using server prices; own-order reads and privileged status changes passed. The documented lifecycle and repeat-cancellation behavior are covered by the suite. | Pass |
| R1-06 | Versioned REST, HTTP codes and unified errors | All 33 API routes use `/api/v1`. HTTP checks verify successful and failed responses; isolated Nginx tests verify JSON 413/502/403 responses and security headers. API requests asking for HTML still receive JSON errors. | Pass |
| R1-07 | Documentation easy to execute | Both Postman collections parse correctly. Every registered API method/path is represented in the main collection after adding three missing administrator GET requests, which were also exercised successfully. Dependency-free demos execute the required proof flows. | Pass |
| R1-08 | JWT authentication and roles | Full Laravel suite covers missing, invalid and expired tokens; real HTTP tests verify issuance, refresh, revocation/logout and refusal of public admin escalation. | Pass |
| R1-09 | Own orders only; admin-only status mutation | Real two-customer ownership/role proofs reject foreign-order reads and customer admin mutations. Tests verify the rejected mutation leaves status unchanged. | Pass |
| R1-10 | Login limiting and security headers | Six failed attempts followed by 429 passed; another account retains its own allowance. Security headers were checked on successes and API/proxy failures. | Pass |

The core is a **modular monolith**: its domain modules are not independently deployed microservices. The separately deployed inventory service is specifically required by requirement 2. A second process running the same core image for scheduled recovery does not change those domain boundaries.

## Requirement 2 — inter-service communication

| ID | Obligation | Final evidence | Result |
| --- | --- | --- | --- |
| R2-01 | Different-language inventory service, separate database, shared contract | Laravel/PHP core and Node.js inventory own distinct PostgreSQL databases. Source inspection confirms stock reads/writes go through InventoryGateway and the shared proto, without inventory SQL in the core. | Pass |
| R2-02 | Quantity query, reserve and return operations | Fifteen real gRPC/PostgreSQL tests pass, exercising GetStock, multi-item Reserve and idempotent Release, plus administrative SetStock. | Pass |
| R2-03 | Product details show warehouse availability | Catalog/detail API values come from GetStock. HTTP checks alter warehouse stock and observe changed availability; outage browsing reports unknown stock rather than invented zero. | Pass |
| R2-04 | One atomic request reserves all items before order creation | Checkout sends the complete cart in one Reserve call, then persists the order. Real inventory tests verify multi-item success/rejection and no partial deduction. PHP gateway tests inspect the multi-line request. | Pass |
| R2-05 | Clear rejection when quantity is insufficient | Real HTTP checkout returns structured 409, with no saved order and unchanged inventory. | Pass |
| R2-06 | Deadline on every call; stopped/slow service yields a bounded clear response | All four RPCs pass through the same deadline wrapper. Stopped inventory: 503 in **1107 ms**, Retry-After 5, no order; recovery after restart in **946 ms**. A 1500 ms artificial RPC delay yielded structured 503 in **1061 ms**, no order and eventual stock restoration. | Pass |
| R2-07 | Restore reservation if saving the order fails | The real PHP/kernel demo deliberately failed order-line persistence after confirming remote stock deduction: local order rolled back, stock restored and cart retained. Durable recovery also passed after a failed release and separate process exit. | Pass |
| R2-08 | Exactly one winner for the last unit | Ten independent buyers: **one 201, nine 409s, one persisted order, final stock zero**. All losing responses and stored counts were asserted. | Pass |

Failure demonstrations used a **500 ms per-call test deadline**; the repository's normal default is 2000 ms. An HTTP failure can include both Reserve and compensation deadlines, so its bound is longer than one RPC deadline.

## Executed checks

| Check | Result |
| --- | --- |
| Laravel suite | **88 tests passed, 400 assertions**, failure-on-warning/notice enabled; SQLite plus inventory fake for core isolation. |
| Real inventory suite | **15 passed, zero failed**, real gRPC and PostgreSQL in isolated schemas. |
| Architecture guard | Pass; public interfaces, dependency direction, owned ORM targets. |
| `verify-requirements.mjs` | Pass; security, images, catalog, cart/checkout, lifecycle, notifications and errors. |
| `verify-modularity.mjs` | Pass; snapshots/events, deletion/history/cart rules, admin views and notification ownership. |
| `race-condition.mjs` | Pass; one winner, all nine losers 409, stock/count assertions. |
| `inventory-outage.mjs` | Pass; bounded 503, browse fallback, recovery, preserved-cart checkout. |
| `slow-inventory.mjs` | Pass; deadlines, no order, late reservation/compensation settles without leaked stock. |
| `persistence-failure.php` | Pass; deliberate local failure after real reservation compensates correctly. |
| `reservation-recovery.php` | Pass; prepare, unavailable, recover and repeated recovery with the scheduler active. |
| `verify-presentation.php` | Pass; one and eight orders each use five SQL queries for batched enrichment. |
| `proxy-errors.mjs` | Pass; JSON proxy errors plus revalidation headers on stable asset paths. |
| `gui-browser.mjs` | Pass in installed headless Brave; actual uploads render, customer/admin flows work, reload authentication works, delayed earlier responses preserve newer searches, no runtime/CSP errors, no overflow at 390 px. |
| `persistence-restart.mjs` | Pass against Compose container names: all six restarted, **seven orders**, stock and an existing JWT survived named volumes. Uploaded PNG was fetched successfully afterward. |
| Dark-theme inspection | Actual browser computed styles are dark; sampled opaque text/background pairs are at least **5.60:1**. The live port-8080 stylesheet matches the verified port-18082 stylesheet and revalidates on updates. |
| Documentation | All 33 API method/path pairs represented; changed local links resolve; JSON/scripts and diff checks pass. |

The test deployment used project `novacart-final-audit`, port 18082, its own databases/volumes and test-only administrator credentials. The user's separate `distributed-ecommerce` stack on port 8080 was not used for stock mutations, outages, races or persistence failures. Only its **web container** was explicitly recreated to apply the requested GUI changes. The user's application/database/inventory containers and volumes were preserved.

No images, dependencies or host packages were downloaded during this audit. Source builds reused cached dependency/native layers and the bundled gRPC extension; a fully uncached network installation is not claimed.

## Findings resolved during the audit

1. **Incomplete executable documentation:** added administrator product listing, creation-form metadata and edit-form metadata to the Postman collection. All registered API routes are now represented.
2. **GUI response ordering:** a delayed initial catalog request could overwrite a newer filtered result. A deterministic browser regression produced nine unfiltered cards where one was expected before the fix. Catalog requests now use a generation counter; the regression and complete browser workflow pass afterward.
3. **Stale GUI assets after deployment:** Podman retained the existing web container when only its image tag changed. Explicit web-only recreation applied the image. Stable JS/CSS paths now send `Cache-Control: no-cache`, and browser QA disables its own cache to verify current assets rather than an older cached module/theme.
4. **Demo orchestration:** recovery intent aging now occurs after inventory is stopped, preventing the correctly running scheduler from recovering a fixture before the intended failure phase. The restart demo accepts a container-name suffix for Compose projects. These changes affect demos, not order behavior.
5. **Requested dark GUI:** replaced light backgrounds with dark surfaces, retained green accents and readable form/error states. No frontend dependency or image download was needed.

One early compensation run overlapped the intentional warehouse outage and failed during fixture setup; it was rerun after restoring inventory and passed. The first recovery attempt also overlapped scheduled recovery, so its timing was corrected and all phases rerun successfully. A browser initially reused older cached assets; fresh-asset inspection and reruns confirmed the final deployed code. These were retained as test/deployment findings rather than counted as successful first attempts.

## Boundaries of this sign-off

- No outstanding technical obligation from the supplied first two presentations was found after these fixes. Team participation remains excluded by user instruction. Requirements 3–6 are not being signed off.
- Functional acceptance is not a proof of correctness for every possible failure or production load. Product administration and cancellation span independent database commits; this audit does not claim distributed atomicity for those operations. Checkout recovery needs eventual access to the core database and inventory.
- The core products schema retains an **unused legacy `stock_quantity` column**. It is not fillable or used as availability/order stock authority; all 19 audit product rows retained zero there while warehouse stock changed through gRPC. The model comment now states this accurately. No legacy column or user data was dropped during verification.
- The bundled extension targets the pinned Linux amd64 PHP/glibc runtime. Different PHP ABIs or native ARM/Alpine images require a matching artifact, as documented with it.

Temporary test containers, named volumes, network and browser profile were removed after verification. The user's live stack remains running with the dark GUI. Changes stay in focused local commits on `test/requirements-1-2-acceptance`; nothing was pushed or merged into main.
