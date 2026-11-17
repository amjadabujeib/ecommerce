# Cache deletion and operation-based database routing

Date: 2026-11-17. Branch: `fix/catalog-invalidation-db-routing`.

## Change

The evaluator requested physical Redis deletion instead of versioned keys, and
replica selection by database operation rather than API URL/HTTP verb.

Catalog keys are now stable and stored exclusively in Redis database 2. Any
product/category change invalidates the whole catalog, including filtered lists
and related-product representations. The replacement migration removes the old
counter/triggers and creates a durable boolean pending-invalidation flag. Database
triggers acquire an exclusive transaction advisory lock and mark that flag for
inserts, updates, deletes and truncates. Shared transaction locks protect cache
access/fills; deletion takes the exclusive lock. Application commits attempt
invalidation immediately, and cache access recovers unfinished invalidation.
Clearing the flag happens after successful Redis deletion in the control
transaction. A crash or outage cannot silently acknowledge unfinished work.

Laravel's read/write PDO machinery routes mutations, transactions and locking
queries to primary. Ordinary reads use replica after checking the actor's WAL
fence; explicit authoritative reads use primary. No API path or HTTP verb selects
a database. The middleware observes committed mutations and records fences even
if the response is an error. Authentication and model binding stay primary.
Failed mutations are never automatically replayed. A failed ordinary replica
query can retry once on primary. Console jobs default to primary.

A separate primary connection performs consistency bookkeeping and cache locking.
Both cached misses and uncached catalog loads compare replica replay with current
primary WAL, avoiding a catalog version counter. Live stock and reservation
operations still use the real gRPC Inventory service; no inventory call occurs
inside the catalog cache lock.

## Verified results

- Architecture guard passed; module dependencies remain acyclic and use public interfaces.
- Laravel: **132 tests / 624 assertions passed**, including the 121-test existing
  suite plus new routing, fallback, cache deletion, rollback and unsafe-store tests.
- Browser API-client regression suite: **6 tests passed**. PHP formatting, JavaScript
  syntax and diff whitespace checks passed.
- The self-cleaning integration harness passed on the final behavior with real
  PostgreSQL/standby, Redis and gRPC Inventory. It proved physical deletion,
  stable keys, unrelated Redis-data preservation, ETags/304, WAL fences under
  paused replay, current cart/checkout prices, persisted orders and return to replica.
- It also passed deterministic fill/write interleaving; Redis stalls/refusal and
  recovery; process interruption before invalidation acknowledgement; transactional
  rollback; primary authentication after admin demotion while replica lagged;
  uncached catalog freshness; replica outage fallback; table/advisory lock contention;
  and replacement migration rollback/reapplication.
- A real last-unit race produced one 201, one 409, one persisted order and stock
  zero. An injected order-persistence failure left no order and released its
  inventory reservation. An Inventory outage returned 503 without creating an
  order; stock was unchanged after recovery.
- A read inside POST used replica. A committed write inside GET still recorded a
  fence despite returning HTTP 500. Unit tests also prove an explicit primary read
  does not publish a write, and lost mutations are not replayed automatically.
- All **35,973 measured k6 requests returned HTTP 200** across four comparable
  stages. Reports are in [the current result directory](../performance/results/2026-11-17-routing/).
  Native per-endpoint metrics remain in `/tmp/novacart-routing-k6` for this session.

| Stage | Workload | p50 ms | p95 ms | p99 ms | requests/s |
| --- | --- | ---: | ---: | ---: | ---: |
| baseline | catalog | 28.00 | 34.00 | 55.00 | 135.92 |
| baseline | product | 26.00 | 31.00 | 51.00 | 147.17 |
| baseline | admin_orders | 438.00 | 495.20 | 545.42 | 8.86 |
| baseline | customer_orders | 372.00 | 408.00 | 425.02 | 10.58 |
| indexes-control | catalog | 29.00 | 34.00 | 56.00 | 133.81 |
| indexes-control | product | 26.00 | 32.00 | 52.00 | 145.80 |
| indexes-control | admin_orders | 45.00 | 55.00 | 81.80 | 85.97 |
| indexes-control | customer_orders | 29.00 | 34.00 | 58.00 | 133.17 |
| replica-only | catalog | 29.00 | 34.00 | 57.00 | 132.89 |
| replica-only | product | 26.00 | 32.00 | 53.00 | 145.95 |
| replica-only | admin_orders | 45.00 | 55.00 | 87.00 | 85.84 |
| replica-only | customer_orders | 29.00 | 34.00 | 56.00 | 133.93 |
| replica-cache | catalog | 29.00 | 35.00 | 57.50 | 132.42 |
| replica-cache | product | 26.00 | 47.00 | 54.00 | 144.76 |
| replica-cache | admin_orders | 45.00 | 55.00 | 86.00 | 85.99 |
| replica-cache | customer_orders | 29.00 | 34.00 | 57.00 | 133.23 |

Indexes reduced admin-order median latency from 438 to 45 ms and customer-order
median from 372 to 29 ms in this run. Replica/cache stage differences for catalog
and product requests are small; these measurements do **not** establish a further
throughput gain for Redis. The dataset has only 500 products, and all services
share one laptop. No claimed speedup over the previous versioning implementation
is inferred from this comparison. Successful GET behavior was unchanged by the
subsequent hardening of write tracking and table-lock timeout recovery; the final
source also passed the full integration suite after those changes.

## Reproduce

Prerequisites: local Podman, Node, the cached images named by the harness, and
explicit authorization to start isolated services. No downloads or image builds.

```fish
env DISPOSABLE_CONFIRM=cache-routing node docs/performance/verify-cache-routing.mjs
env DISPOSABLE_CONFIRM=cache-routing RUN_K6=true \
    RESULTS_DIR=/tmp/novacart-routing-k6 node docs/performance/verify-cache-routing.mjs
```

The harness uses a private network and fresh tmpfs PostgreSQL storage. Inventory
uses its own database. Existing containers/volumes are untouched. Each run removes
its containers/network in `finally`; forced termination can require cleaning only
its `novacart-routing-<pid>` names. k6 retains aggregate reports and native metrics
at `RESULTS_DIR`; tokens remain in memory. All stages use the same PHP four-worker
CLI HTTP server, four VUs, three seconds warmup, twenty measured seconds and ETags
enabled. These runs are not directly comparable with historical FPM/nginx runs.

## Deployment and recovery

1. Reserve Redis database 2 exclusively for catalog snapshots. Configure
   `REDIS_CATALOG_DB` if another exclusive database is required. Do not share it
   with sessions, rate limits or another application; invalidation clears it.
2. Coordinate application rollout: stop admitting requests while applying the new
   migration and deploying the new code together. Do not mix old/new workers.
   Rebuild runtime configuration and restart workers as required by deployment.
3. Apply migrations. The initial dirty flag forces deletion before using new cache
   entries. Old versioned keys in Redis database 1 can expire naturally.
4. If Redis is unavailable, serve primary data; keep the flag dirty and retry
   deletion automatically after recovery. Cache coordination waits are bounded
   by a 500 ms lock timeout; Redis defaults to 0.5 s connection/socket timeouts.
5. For code rollback, coordinate downtime again and roll back this migration
   before restoring the previous application code. Its `down()` restores the old
   counter/triggers with a fresh namespace seed so old Redis keys cannot collide.

No migration or deployment has been applied to the user's existing stacks. No deployment has been performed. This report describes local validation,
not production throughput or a guarantee of globally current order reads.

## Review follow-up: authentication and order-detail reads

The first integration harness used array cache and did not exercise revoked JWTs
with the normal database cache, direct credential reads during lag, or an admin
reading another user's newly committed order details. Review identified three
regressions; all three are corrected and now covered with paused WAL replay.

- Database cache reads and locks default to the separate, non-routed
  `primary_control` connection under PostgreSQL. This keeps JWT blacklist reads
  authoritative without changing SQLite test defaults. Explicit
  `DB_CACHE_CONNECTION` / `DB_CACHE_LOCK_CONNECTION` overrides must also refer
  to primary-only connections; do not point these at `pgsql` or `replica`.
- The direct email/password lookup in `AuthController::login()` explicitly uses
  write PDO. Both newly registered identities and changed password hashes are
  read from primary.
- `OrderController::show()` scopes line loading, catalog/customer enrichment and
  resource serialization to primary, consistent with authoritative route binding.

Validation: the full Laravel suite still passes (132 tests / 624 assertions), as
well as the architecture guard and PHP formatting. The real integration harness
passes all prior scenarios plus new checks with both array and database caches:
immediate login, old-password rejection/new-password acceptance, and complete
cross-user order details while the new order and lines are absent on standby.
With database cache it additionally proves logout and refresh revoke the old token
across fresh processes, despite paused replay; the newly refreshed token works.

Independent standards and specification re-reviews reported zero actionable
findings in these three fixes. Both noted the explicit cache-connection override
constraint documented above. They reviewed source and the recorded integration
output; they did not independently repeat the runtime checks. Benchmark stages
were not repeated because these fixes address correctness; existing measurements
remain historical evidence for the earlier source state.

## Second regression pass and fixes

A broader runtime review reproduced four additional cases. All are now fixed:

- Product editing and stock-only updates scope category loading, dropdown options
  and serialization to primary. This covers products/categories created by another
  administrator while replay is paused.
- Admin order-status updates scope their complete response to primary, including
  no-op transitions that do not set the connection's sticky-write flag.
- Notification marking looks up the owned notification on primary before writing,
  avoiding a false 404 when another actor's notification has not replayed.
- Cache publication now uses a short-lived, one-use Redis fill permit. The permit
  is created before loading the snapshot. Invalidation physically flushes permits
  together with snapshots. A Lua script atomically checks the permit, stores the
  snapshot, and consumes the permit; an expired or deleted permit cannot publish.
  `CatalogRedisStore` extends Laravel's Redis store and uses its serialization
  packing for Lua. The dedicated `catalog-snapshots` driver retains stable cache
  keys, 60-second default snapshot TTL and ordinary physical FLUSHDB invalidation.

This closes a gap in PostgreSQL advisory locking alone: terminating the control
session releases its lock before the old PHP fill necessarily stops. The new test
pauses a fill after reading old data, terminates its control backend, commits a
new title and flushes the cache, then resumes the old fill. Both the initial
fallback and a subsequent request now return the new title with the invalidation
flag clean. No stale snapshot is resurrected.

Validation after fixes: 132 Laravel tests / 624 assertions, architecture guard,
6 browser API-client tests, PHP formatting and JavaScript syntax checks pass.
The complete disposable PostgreSQL/Redis/gRPC harness passes the new cases and
all previous concurrency, outage, authentication and reservation checks. The
new cases are permanent assertions in `verify-cache-routing.mjs`.
Historical k6 measurements were not repeated for these correctness changes.
