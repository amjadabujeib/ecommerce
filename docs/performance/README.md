# Requirement 3: performance and consistency

Work is on `feat/requirement-3-performance`, based on the completed requirements
1–2 acceptance branch. The initial checks passed; the [deeper review](../verification/2026-11-11-deep-review.md) found open correctness and failure-handling gaps. Full acceptance remains open.

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

Writes, authentication, authorization, route binding, command-line jobs, cart
quotations, checkout previews and order placement stay on the primary. Eligible order lists, dashboard order
reads, catalog reads and batched profile/product enrichment use the standby.
After a successful authenticated business write, middleware saves the primary's
committed WAL position as a monotonic per-user fence in the primary database.
Each later HTTP request compares standby replay against that fence and falls
back to primary during lag or an unavailable standby. This works across requests
and survives Redis outages. It is not a global guarantee that another user's
order changes are instantly visible on the replica. Product caching additionally
checks a global catalog version to guarantee fresh catalog representations.

Database triggers advance `catalog_versions` in the same transaction as product
or category writes, including bulk SQL writes. Keys are
`catalog:v{version}:{list|detail}:{sha256(parameters)}`, plus Laravel's configured
prefix. List parameters include search, category and page; detail includes ID.
Each entry expires after 60 seconds by default (`CATALOG_CACHE_TTL`). Invalidated
versions become unreachable immediately and expire naturally. A late fill from
an old request cannot replace the current version. Before a cache miss uses the
standby, its catalog version must have caught up with primary. Redis failures
bypass the cache. Only plain catalog snapshots are cached: stock is fetched live
over gRPC, and reservations remain atomic in the inventory service. Private
orders, tokens, notifications, passwords and checkout decisions are not cached.

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

## Verification evidence

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
