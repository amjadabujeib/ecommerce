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
