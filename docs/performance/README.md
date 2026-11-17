# Requirement 3: performance and consistency

The original milestone used `feat/requirement-3-performance`, based on the completed
requirements 1–2 acceptance branch. The current evaluator-feedback follow-up is on
`fix/catalog-invalidation-db-routing`. The initial checks and the [deeper-review remediation](../verification/2026-11-11-deep-review.md) are locally verified. All five earlier gaps are resolved. The [six follow-up findings](../verification/2026-11-11-follow-up-review.md), including cross-origin ETags and restart seeding, are also fixed and regression-verified.

The baseline tools require an isolated PostgreSQL database ending in `_perf`
and APP_ENV=testing. Do not run them on the live shop. Historical fixtures contain
200,000 orders, 600,000 order lines, 1,000 customers and 500 products. They bypass
business events deliberately; inventory fixture stock is still initialized over gRPC.

- `seed.php`: creates the deterministic historical dataset, refusing existing orders.
- `explain.php`: captures EXPLAIN ANALYZE/BUFFERS JSON for actual order-list query shapes.
- `load.mjs`: dependency-free closed-loop HTTP workload; defaults to four clients,
  three seconds warmup and twenty seconds measured per endpoint. Results include
  p50/p95/p99, throughput, elapsed time and all response statuses. Non-200s fail the run.

The fixture customer is `perf-1@example.test` with the test-only password in the
scripts. Pass the disposable administrator password through ADMIN_PASSWORD;
reports never include bearer tokens. PERFORMANCE_LOAD_TESTING bypasses only the
ordinary API rate limit and only in APP_ENV=testing; authentication and the login
limiter stay enabled. Production never bypasses its API limit through this flag.

Build/source image IDs, database plans, comparison results and exact commands
will be recorded as each stage is verified. Native/base/dependency build cache
is allowed; caches of application data are controlled separately per stage.

## Measured database baseline and indexes

Measured on an Intel i5-10500H (12 logical CPUs) using isolated containers on
one host. The live application was also running, so these are local comparative
measurements, not isolated capacity guarantees. The data contained 200,000 orders,
600,000 lines, 1,000 synthetic customers, and 500 synthetic products, in addition
to the normal seed data. Four closed-loop clients used a 3-second warmup and a
20-second measurement for each endpoint. Every recorded request returned 200.
The index-stage app build ran while part of the index-stage workload was running,
so small catalog differences should not be treated as a reliable improvement.

| Workload | Stage | p50 ms | p95 ms | p99 ms | requests/s |
| --- | --- | ---: | ---: | ---: | ---: |
| Catalog | Baseline | 35.86 | 44.66 | 61.67 | 108.17 |
| Catalog | Indexes | 32.42 | 42.55 | 63.54 | 119.87 |
| Product | Baseline | 32.10 | 40.57 | 54.45 | 120.38 |
| Product | Indexes | 33.72 | 45.28 | 59.53 | 114.23 |
| Admin orders | Baseline | 570.25 | 703.15 | 729.13 | 6.83 |
| Admin orders | Indexes | 72.46 | 97.32 | 126.92 | 53.36 |
| Customer orders | Baseline | 504.97 | 654.41 | 709.31 | 7.67 |
| Customer orders | Indexes | 38.81 | 47.59 | 57.77 | 100.85 |

The initial plans repeatedly scanned order items for each count and scanned/sorted
orders for the newest page. The cumulative EXPLAIN execution times in milliseconds
for admin/customer/line queries were 573.486/272.885/31.600 before changes;
39.472/11.203/0.066 after adding `order_items(order_id)`;
0.125/1.187/0.039 after `orders(created_at,id)`; and
0.117/0.109/0.042 after `orders(user_id,created_at,id)`. The JSON plans for
all stages are in [results](results/). Indexes increase storage and add work to
inserts, updates and deletes; only indexes supported by these plans were added.

The order presentation layer was already batched in requirements 1–2. A separate
transactional check confirmed one and eight orders both use five SQL queries,
including product and customer enrichment. This is evidence that the identified
N+1 risk was already addressed; it is not a new fix attributed to this milestone.

## Consistency and cache design

Use `docker-compose.performance.yml` after the root Compose file to enable the
optional milestone. PostgreSQL 17 streams WAL automatically to `db-replica` using
a physical replication slot. The setup job also works with an existing primary
volume; it creates replication credentials, a SELECT-only application role and
default SELECT privileges for future migrations. Redis has no published host port.

Laravel's read/write connection chooses primary for mutations, transactions and
locking reads, and replica for ordinary reads after the user's WAL fence is
replayed. The policy never checks API paths or HTTP verbs. Authentication (including direct credential and JWT blacklist reads), complete order
detail loading, route binding, authoritative cart/checkout reads and consistency bookkeeping explicitly
use primary. Console jobs stay primary unless a test explicitly configures a
request context. The middleware observes database commits rather than assuming
that a particular HTTP method wrote data. A committed write followed by an error
response still records a fence. A rolled-back transaction does not.

Keys are `catalog:{list|detail}:{sha256(parameters)}`, plus Laravel's configured
prefix. List parameters include search, category and page; detail includes ID.
Each entry expires after 60 seconds by default (`CATALOG_CACHE_TTL`). The dedicated
`catalog` cache store uses the exclusive Redis `catalog` connection/database 2
(`REDIS_CATALOG_DB`). General Redis/database 1 data is not invalidated. Never
share this catalog database with other uses: invalidation physically flushes it.
The implementation rejects the configured general Redis databases and incompatible
cache stores before it can flush anything.

Product/category triggers mark a boolean `catalog_cache_state.needs_invalidation`
inside the transaction, covering bulk/direct SQL and soft deletion. Successful
application commits attempt physical deletion immediately; pending invalidation
is also drained before cache access, covering external writes and process loss.
The flag clears only after successful Redis deletion. Shared PostgreSQL advisory
transaction locks cover cache reads/fills; mutations and deletion acquire the same
lock exclusively. One-use Redis fill permits additionally prevent stale publication if the PostgreSQL
control connection is lost: FLUSHDB revokes outstanding permits, and publication
checks and consumes the permit atomically in Lua. Coordination
uses a separate primary connection, a 500 ms lock timeout, and no inventory RPCs
inside the lock. Failures serve uncached primary data and retain recovery state.

Before loading a catalog snapshot from replica, compare its replay position with
current primary WAL, including when caching is disabled. There is no catalog
version counter or versioned namespace. Only plain catalog snapshots are cached:
stock is fetched live over gRPC, and reservations remain atomic in Inventory.
Private orders, tokens, notifications, passwords and checkout decisions are not
cached. The WAL fence protects an actor's own writes across requests; it does not
make every order read globally current. Failed replica reads may retry on primary;
mutations are not automatically replayed after connection loss.

Product ETags hash the complete JSON representation, including related products
and live stock. `If-None-Match` uses standard Symfony conditional handling:
unchanged returns 304 with an empty body; catalog or stock changes return 200
with a new tag. Responses use `private, no-cache` so clients revalidate before
reuse. This saves response bytes; it still performs the stock check. The optional
[Postman proof](../api/NovaCart-performance.postman_collection.json) exercises 200/304.

## Reproduction on disposable data

Run from the repository root. These fish-compatible commands create only the
`novacart-perf` project, with its own volumes and port. Set an administrator
password for this disposable stack. Images and locked dependencies must already
be available or downloaded deliberately; the Redis image is approximately
15.7 MiB compressed. During implementation, capped download accounting reached
28,256,093 bytes of the authorized 30 MiB before a host restart removed the
temporary counter. Subsequent builds used cached layers with networking disabled.

```fish
set -x APP_PORT 18090
set -x ADMIN_PASSWORD 'YourDisposablePassword'
docker compose -p novacart-perf -f docker-compose.yml -f docker-compose.performance.yml -f docs/performance/compose.test.yml up -d --build
docker cp docs/performance/seed.php novacart-perf_app_1:/tmp/performance-seed.php
docker exec --user www-data novacart-perf_app_1 php /tmp/performance-seed.php
docker cp docs/performance/explain.php novacart-perf_app_1:/tmp/performance-explain.php
docker exec --user www-data novacart-perf_app_1 php /tmp/performance-explain.php
```

The seeder refuses existing orders. Use fresh disposable volumes for a new dataset;
do not run `migrate:fresh` or remove volumes belonging to the live shop. The earlier
baseline image was `814c6d5e59fe1ff1cc5c58e1b4063c36f96c43d5f46cec1fe13c35b3d3ca43a9`;
the final implementation image was
`62087e020defc5c4de40082c311d2e8c03b7ae67e5621acc19fe2cfcd0bc65fb`.

`set-stage.php` modifies only cached configuration in an explicitly guarded testing
app/database ending in `_perf`. It never prints configuration or credentials.
FPM must reload because its opcode cache does not watch file timestamps. Restarting
the app normally reconstructs configuration from its environment and restores
all performance features from the Compose overlay.

```fish
docker cp docs/performance/set-stage.php novacart-perf_app_1:/tmp/set-stage.php
for stage in indexes-control replica-only replica-cache
    docker exec novacart-perf_app_1 php /tmp/set-stage.php $stage
    docker kill --signal USR2 novacart-perf_app_1
    env STAGE=$stage DURATION_SECONDS=8 WARMUP_SECONDS=1 OUTPUT=docs/performance/results/controlled-$stage-load.json node docs/performance/load.mjs
end
docker exec novacart-perf_app_1 php /tmp/set-stage.php diagnostics
docker kill --signal USR2 novacart-perf_app_1
env DISPOSABLE_CONFIRM=novacart-perf node docs/performance/verify-consistency.mjs
```

The consistency script pins the isolated container names and local port, pauses
standby replay, registers a customer, updates a product, places an order, verifies
immediate visibility and stock-sensitive ETags, then resumes replay in `finally`
and checks reads return to the replica. It leaves disposable fixtures behind;
run it **after** comparative measurements. The controlled runs used the same
post-demonstration snapshot throughout all three stages, without writes to
products/orders between stages. Their shorter duration is a supplementary
comparison; do not compare their absolute rates directly against the earlier
20-second runs on the host before restart. Diagnostic headers were disabled in
all three controlled runs. The earlier combined run enabled diagnostics and is
retained transparently as `replica-cache-load.json`.

Recovery: if interrupted during the consistency demonstration, run
`docker exec novacart-perf_db-replica_1 psql -U novacart -d novacart_perf -c 'SELECT pg_wal_replay_resume()'`.
Stop/remove the isolated stack and its disposable volumes with the same three
Compose files and project name followed by `down -v`. This removes its replication
slot with its primary volume too. When retaining a primary but retiring its replica,
drop the inactive `novacart_replica` slot on that primary to release retained WAL.
An interrupted first base backup may require recreating only the disposable
replica volume; never erase the primary to repair a replica. Password changes for
replication require updating the persisted standby connection credentials too.

## Historical verification evidence

The following records describe the earlier versioned implementation. Current
replacement evidence is in [the 2026-11-17 report](../verification/2026-11-17-cache-routing.md).

- Laravel: 94 tests passed with 442 assertions, including cache expiry, invalidation, ETags and production rate-limit guards.
- Last-unit race through real HTTP/gRPC/PostgreSQL: 10 buyers, one 201, nine conflicts, exactly one persisted order, final stock zero.
- Module boundary check passed; Users, Products, Orders and Notifications remain
  modules in one Laravel application, with inventory accessed through its adapter.
- Real PostgreSQL standby reported recovery mode; primary reported `streaming`;
  application reader credentials could SELECT and a write to standby was rejected.
- Paused-replay tests passed for new-customer registration, new orders, immediate
  product edits, cache invalidation, changed ETags and return to replica after replay.
- Redis database 1 contained catalog keys with a positive, decreasing TTL (23 seconds
  observed); HTTP diagnostics confirmed misses followed by hits. SQLite behavior
  tests additionally cover TTL expiry and category/product invalidation.
- Stopping Redis returned 200 with `X-Catalog-Cache: unavailable`; stopping standby
  returned 200 with `X-Read-Database: primary`; both services were restored.
- The requirements 1–2 real HTTP workflow passed with the overlay: JWT revocation,
  ownership, roles, totals, stock, cancellation, notifications and login throttling.
- Existing dependency audit reported Symfony advisories before these optimizations.
  They were not upgraded or assessed for exploitability in this milestone and remain
  a separate dependency-maintenance item. No inventory protocol changes were made.

The standby and Redis add processes, memory, connection setup and consistency
checks. A replica spreads read work; it does not promise lower latency when every
container shares one laptop. The cache reduces catalog SQL work; small catalogs
can cost less to query than to validate and retrieve over additional connections.
No numerical performance threshold was specified by the course. These measurements
report the costs as well as the improvement.

## Controlled layer comparison

Same final image, dataset, four clients, one-second warmup and eight seconds
measured per endpoint; all responses were 200. Latencies are milliseconds.

| Workload | Stage | p50 | p95 | p99 | requests/s |
| --- | --- | ---: | ---: | ---: | ---: |
| catalog | indexes-control | 27.17 | 33.53 | 39.70 | 143.70 |
| product | indexes-control | 24.37 | 30.09 | 32.75 | 161.26 |
| admin_orders | indexes-control | 43.14 | 51.75 | 56.89 | 91.24 |
| customer_orders | indexes-control | 28.81 | 34.97 | 36.88 | 137.45 |
| catalog | replica-only | 41.34 | 49.99 | 55.88 | 95.30 |
| product | replica-only | 38.04 | 45.43 | 50.92 | 103.02 |
| admin_orders | replica-only | 57.36 | 67.48 | 74.85 | 68.81 |
| customer_orders | replica-only | 41.56 | 48.53 | 53.09 | 95.03 |
| catalog | replica-cache | 24.58 | 33.45 | 40.07 | 156.13 |
| product | replica-cache | 23.18 | 28.67 | 30.57 | 168.83 |
| admin_orders | replica-cache | 58.12 | 69.15 | 75.32 | 67.82 |
| customer_orders | replica-cache | 41.75 | 49.86 | 53.71 | 94.45 |

Redis increased catalog/product throughput compared with the replica-only stage,
while order rates stayed similar because orders are not cached. Compared with
primary-only indexed reads, catalog gains were smaller, and order reads carried
the extra cost of replica connections and fence checks. The large baseline-to-final
improvement on orders comes from the measured indexes, not Redis. The short local
runs are suitable course evidence, not production sizing or a statistical guarantee.

## Follow-up review

Two independent standards/specification reviews found the same price-consistency
regression: with a customer's existing cart already replicated, pausing replay
and changing its product price as another administrator left cart/checkout
previews on the old price, while order placement used the new primary price.
The expanded real-HTTP demonstration reproduced a failing `19.99` versus `20.99`
cart subtotal before the fix. The router now keeps `/api/v1/cart` and
`/api/v1/checkout` reads on primary. The demonstration prepares and replicates
the cart **before** pausing replay, then checks current cart and checkout subtotals
and the matching placed-order total after the price change. It passed after the
fix. Both independent second review passes reported no remaining actionable
findings. This does not remove the possibility of bugs outside the exercised cases.

After the review fix, all 94 Laravel tests (442 assertions), the module guard,
and the expanded real-lag demonstration passed. The reviewed source also built
offline as `11994b18aadfd3bd7513d9d9bfd12bdb1d9babbca66eee5a0845eedc3f15df10`.
Earlier load artifacts remain historical measurements; the fix only changes cart
and checkout quotation routing, which were not benchmarked endpoints.

## Deep-review fixes

See [the remediation evidence](../verification/2026-11-11-deep-review.md) for all
five corrected findings and the 100-line RPC validation follow-up. Run
`env DISPOSABLE_CONFIRM=novacart-perf node docs/performance/verify-deep-fixes.mjs`
after the standard seed, with ADMIN_PASSWORD set. This is destructive test tooling
for the fixed container names and port, not the live shop. It verifies durable
cancellation, concurrent cart changes/checkout, numeric bounds and Redis stalls.
The default Redis cache connect/read deadlines are 0.5 seconds, configurable via
REDIS_CACHE_TIMEOUT and REDIS_CACHE_READ_WRITE_TIMEOUT (minimum 0.05); retries
are disabled for this optional cache. A stalled-cache probe completed with 200
in 549.8 ms. All 101 Laravel tests (496 assertions) passed. Historical load tables
are unchanged measurements, not new benchmarks attributed to these fixes.

## Six follow-up fixes

[Final evidence and recovery notes](../verification/2026-11-11-follow-up-review.md)
record 109 tests/535 assertions, both deletion/checkout lock orders, supported
Unicode/password cases, seed preservation on existing and fresh installations,
and actual browser stock-edit and cross-origin ETag checks. Use
`env DISPOSABLE_CONFIRM=novacart-perf node docs/performance/verify-follow-up-fixes.mjs`
with ADMIN_PASSWORD set only against the seeded disposable stack. The separate
browser harness is [follow-up-browser.mjs](../demo/follow-up-browser.mjs).
The existing load tables remain historical measurements of their frozen images.

## Continued session and administration hardening

[Continued verification](../verification/2026-11-11-session-admin-hardening.md)
records concurrent category allocation, image lifecycle cleanup, typed authentication
validation, canonical administrator identities and browser session isolation. Run
`node --test tests/browser/api-client.test.mjs` for the dependency-free client tests.
The guarded runtime harness is `docs/performance/verify-session-admin-fixes.mjs`;
real expired-JWT/browser coverage is `docs/demo/session-browser.mjs`. These are
correctness checks, not revised performance measurements.

## k6 evaluator workload

For new evaluation runs, use [run-k6.mjs](run-k6.mjs), which starts the actual
[load.k6.js](load.k6.js) workload sequentially for the four existing endpoints.
The original `load.mjs` and committed measurements remain historical evidence;
rerun **every** comparison stage with k6 rather than mixing generators.

Requires local k6 (verified with 2.3.0), Node.js and the seeded performance stack:

```fish
read --silent --export --prompt-str 'Performance administrator password: ' ADMIN_PASSWORD
env STAGE=A-baseline CONCURRENCY=4 WARMUP_SECONDS=3 DURATION_SECONDS=20 \
    OUTPUT=/tmp/A-baseline-k6.json node docs/performance/run-k6.mjs
```

`STAGE` only labels results. Select `indexes-control` and remove the three added
indexes before a baseline. Follow the [verification and reproduction guide](../verification/2026-11-17-cache-routing.md)
for index restoration, stage switching, EXPLAIN and consistency checks.

k6 uses constant VUs and excludes warmup/login from custom response-time metrics.
The table reports p50/p95/p99 wall-clock milliseconds and completed requests divided
by measured elapsed time including in-flight completion. Built-in global k6 metrics
include setup/warmup; use the custom table for comparisons. Warmup and measured
requests must return 200; failures abort and no valid combined report is produced.
The script disables redirects, targets only the local performance API, and makes
GET requests after login. It does not alter indexes or performance settings.

Outputs: readable terminal table, combined JSON and `.txt` table, per-endpoint JSON
and native `.metrics.json` summaries. Tokens stay in memory. No remote JS imports
are used; the runner disables usage reporting. Do not use verbose HTTP debug logs,
which may expose authentication data. `K6_BIN` can select an installed executable.

Primary references: [constant VUs](https://grafana.com/docs/k6/latest/using-k6/scenarios/executors/constant-vus/),
[execution context](https://grafana.com/docs/k6/latest/javascript-api/k6-execution/),
and [custom summaries](https://grafana.com/docs/k6/latest/results-output/end-of-test/custom-summary/).

Tool verification on 2026-11-12: k6 2.3.0 executed all four real local endpoints
with one VU, one-second warmup and five-second measurements. All measured responses
returned 200; JSON validation confirmed ordered percentiles and consistent
throughput arithmetic. These are tooling smoke checks, not replacement baseline
or acceptance benchmarks. No routing settings, indexes or business records were
changed; authentication creates ordinary JWT/cache activity. A fresh four-VU,
20-second-per-endpoint comparison must be run for evaluation.

The N+1 presentation check now accepts `--verbose`: copy
`laravel-app/tests/Architecture/verify-presentation.php` into the test app and run
`php /tmp/verify-presentation.php --verbose` as www-data. It prints actual SQL,
ID bindings, timings and query counts for 1/4/8 orders containing distinct products.
Verified against the real performance PostgreSQL database: five queries at each
size; fixtures rolled back. It is a focused CLI presentation trace, not whole-HTTP
instrumentation, and its SQL timings are not additional load benchmarks.

For an illustrative orders/lines before-and-after demo, run
`node docs/performance/run-nplus1.mjs` from the repository root. The runner copies
`nplus1-before-after.php` to the existing disposable performance app on every run,
including after container recreation. It requires testing mode and database
`novacart_perf`. For 1/4/8 orders, separate line lookups use 2/5/9 SQL queries and
eager loading uses 2/2/2. This intentionally inefficient example is not a historical
deployed version; it excludes catalog/customer enrichment. Temporary fixtures
roll back, though ID sequences advance. The comparison passed on the real local
performance PostgreSQL database on 2026-11-12.

## Cache deletion and operation routing verification (2026-11-17)

The replacement uses stable keys, physical deletion and native database-operation
routing. Run its self-cleaning integration harness only with authorization to start
isolated services; it requires already-cached images and performs no downloads:

```fish
env DISPOSABLE_CONFIRM=cache-routing node docs/performance/verify-cache-routing.mjs
```

The harness creates a private network, fresh primary/standby PostgreSQL, Redis,
Inventory with its own database, and a PHP HTTP server. It uses neither existing
stack containers nor persisted volumes. It removes its containers/network in
`finally`. Forced termination may require manually removing only the printed
`novacart-routing-<pid>` resources. Tests include physical deletion, stable keys,
rollback, Redis refusal/stall recovery, interrupted invalidation, concurrent cache
fill versus write, WAL lag, HTTP-method-independent routing, current checkout
prices and real gRPC order placement. PHP/SQLite tests remain complementary.

For four comparable k6 stages on 200,000 orders / 600,000 lines:

```fish
env DISPOSABLE_CONFIRM=cache-routing RUN_K6=true \
    RESULTS_DIR=/tmp/novacart-routing-k6 node docs/performance/verify-cache-routing.mjs
```

All stages use four VUs, three seconds warmup and twenty measured seconds per
endpoint. ETags remain enabled throughout. Baseline removes the three measured
indexes; indexes-control restores them; replica-only enables read routing;
replica-cache additionally enables Redis. These runs use PHP's four-worker CLI
HTTP server on an ephemeral loopback port. They are internally comparable but
must not be mixed numerically with historical FPM/nginx measurements or described
as a measured improvement over the old versioning implementation.

Current-source results: [four-stage measurement and validation report](../verification/2026-11-17-cache-routing.md#verified-results).
Indexes account for the measured order latency improvement; this run does not
show an additional catalog throughput improvement from Redis or replica routing.

The PostgreSQL database cache and its locks default to the primary-only
`primary_control` connection. If setting `DB_CACHE_CONNECTION` or
`DB_CACHE_LOCK_CONNECTION`, use a primary-only connection. The regression harness
also tests login/password freshness, logout/refresh revocation and complete
cross-user order details with paused replay, under both array and database caches.
