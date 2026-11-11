# Requirement 3: performance and consistency

Work is on `feat/requirement-3-performance`, based on the completed requirements
1–2 acceptance branch. This milestone is in progress; it is not yet signed off.

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
