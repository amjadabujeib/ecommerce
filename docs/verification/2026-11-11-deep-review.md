# Deep correctness review — 2026-11-11

Reviewed source at `e3e599d` on `feat/requirement-3-performance`. All source changes
were committed before review. Local branches were `main`,
`test/requirements-1-2-acceptance` (27 commits beyond main), and
`feat/requirement-3-performance` (six commits beyond acceptance). No merge or push
was performed. Standards/correctness reviewed the work since main; the requirement
3 specification review compared against the acceptance branch.

The earlier 94-test suite and demonstrations remain historical passing checks.
The five findings below show those checks did not establish full acceptance.
**All five are now resolved and regression-verified; see the remediation evidence below.** The sections below preserve the original counterexamples at `e3e599d`. The initial review changed documentation only; later commits implement the fixes.
The first four are inherited core behaviors; the fifth concerns the new Redis path.

## Standards and correctness: four confirmed findings

### P1 — Failed cancellation can expose an active order's stock

Source: `laravel-app/app/Modules/Orders/Services/OrderService.php`, lines 39–44.
Remote Release executes before the cancellation status is persisted. A later SQL
failure rolls back the status but cannot roll back inventory's committed release.
The still-pending order is also allowed to transition to completed.

Reproduced through real HTTP, gRPC and PostgreSQL on `novacart-perf`:

1. Create a stock-one product, buy its one unit, and observe stock zero.
2. Install a temporary BEFORE UPDATE trigger on orders that raises an exception
   only when that fixture order is changed to cancelled.
3. PATCH its status to cancelled. HTTP returns 500; SQL still shows pending,
   but the product's live gRPC stock is one.
4. Remove the temporary trigger/function, then let another customer purchase
   that unit. The second checkout returns 201.
5. Complete the original order: HTTP returns 200. There are two active/completed
   orders for the product originally holding only one unit.

The temporary trigger/function were removed in `finally`; a follow-up query
confirmed zero functions named `review_reject_cancel`. A separate SQLite/fake
inventory reproducer also confirmed the ordering with ten assertions; that
supplement is not a substitute for the real distributed reproduction above.

Required correction: record a durable cancellation intent before remote release,
block incompatible transitions, and reconcile local completion after ambiguous
release responses or persistence failures. An operator's voluntary retry alone
does not enforce the invariant.

### P2 — One cart can be checked out twice

Source: `laravel-app/app/Modules/Orders/Services/CheckoutService.php`, lines 45–81.
The cart snapshot precedes reservation/order persistence, and blanket clearing
happens after the order transaction. Requests are not serialized by cart identity
or cart version; each request generates a different reservation ID.

Real reproduction: register one customer, add one unit of a sufficiently stocked
product, then send two POST /orders requests concurrently using the same token.
Both returned 201 and produced distinct persisted order IDs `300025`, `300026`.
A later order-list request returned both orders. The earlier last-unit race uses
independent customers and stock one, so it does not cover this failure.

Required correction: serialize or version cart consumption so one cart revision
can create at most one order. Preserve items added during checkout rather than
clearing unpurchased additions.

### P2 — Concurrent cart additions lose successful changes

Source: `laravel-app/app/Modules/Orders/Services/CartService.php`, lines 98–108,
195–220. The initial cart read is outside the replacement transaction. A later
write removes every item absent from its earlier snapshot.

Real reproduction: establish an empty stored cart, then concurrently POST ten
different product IDs to it, quantity one each. All ten returned 200, but the
final cart had only nine distinct lines; product 10005 disappeared. This used
real HTTP/PostgreSQL and live inventory reads, with one customer's token.

Required correction: put the complete read/modify/write under a common cart lock,
or use atomic per-line mutations that cannot erase unrelated successful writes.
Coordinate the same mechanism with checkout consumption.

### P2 — Accepted prices and quantities can overflow order storage

Source: `laravel-app/app/Modules/Orders/Services/CheckoutService.php`, lines 134–150;
product validation allows price up to 99,999,999.99 while order total is NUMERIC(10,2).

Real reproduction: create a product priced 60,000,000.00 with stock two (201), add
two to a cart (200), and view checkout subtotal 120,000,000.00 (200). POST /orders
returns 500 because the summed total cannot fit. Reservation compensation did
restore stock to two. SQLite does not enforce this PostgreSQL precision limit.

Required correction: reject an out-of-range aggregate before reservation with a
validation/domain response, or widen and consistently validate monetary storage.

## Specification: one confirmed finding

### P2 — A stalled Redis service blocks the intended cache bypass

Source: `laravel-app/config/database.php`, lines 181–191; synchronous cache read
in `laravel-app/app/Modules/Products/Services/CatalogReader.php`, line 68.
No Redis cache connect/read deadline is configured. Installed Predis only applies
an explicit stream read timeout if `read_write_timeout` is provided. Runtime PHP
`default_socket_timeout` is 60 seconds. Catching the eventual exception does not
provide a prompt fail-open response.

Real reproduction: SIGSTOP only `novacart-perf_redis_1`, request the product API
with curl's five-second limit, then restore SIGCONT in an EXIT trap. The request
received zero bytes, HTTP 000, elapsed 5.000816 seconds, curl exit 28. This confirms
failure to return within five seconds; the probe deliberately did not wait the
full default timeout or assert an observed 504. Redis was resumed and later
reported healthy. Stopping Redis previously tested connection refusal only.

Required correction: configure short cache-specific connection and read/write
timeouts and prove stalled-server fallback. Preserve SPEC's bounded failure
handling; the optional cache must not occupy workers for the default socket limit.

## Environment and scope

All mutations used the explicitly authorized disposable `novacart-perf` project
and testing database `novacart_perf`, on host port 18090. No new downloads,
production-data mutations, or changes to the application's logic were made.
Fixture orders/users/products remain in the disposable database. Services were
restored; Redis was healthy and standby replay was not paused at the final check.
Temporary reproduction scripts lived under /tmp; the steps and measured evidence
above are retained here. These are targeted counterexamples, not passing new
acceptance tests. Further source-only resilience hypotheses were excluded from
confirmed findings.

## Remediation and verified result

Implementation commits:

- `56d44a0`: persist cancellation intent before remote Release; block incompatible
  status changes with 409 while pending; finish status and intent atomically.
  `orders:recover-cancellations` retries pending intents each minute through the
  existing scheduler. Manual cancellation retries use the same reservation ID.
- `3289593`: acquire the persistent cart-row lock before all cart read/modify/write
  operations and hold it through checkout reservation, order persistence and cart
  consumption. Keep the checkout intent outside that transaction for crash recovery.
  Validate aggregate total before Reserve; reject >99,999,999.99 with 422 and retain
  the cart. Also align cart/checkout with the inventory's existing 100-line cap,
  returning errors.cart before any Reserve rather than allowing an internal RPC error.
- `5a99ac4`: apply 0.5-second connect and read/write timeouts and zero retries to
  the optional Redis cache connection; bound stalled-server failure as well as
  connection refusal. Other cache/session/JWT state keeps its existing store.

Verification on the updated source:

- Module-boundary guard passed; 101 Laravel tests passed, 496 assertions. New tests
  include failure after remote Release, timeout after Release commits, retry/recovery
  idempotency, inventory outage during cancellation, numeric boundary success,
  aggregate rejection, new-line rejection at capacity and legacy-cart repair.
- Real HTTP/gRPC/PostgreSQL/Redis regressions are retained in
  [verify-deep-fixes.mjs](../performance/verify-deep-fixes.mjs). The cancellation
  persistence fault now leaves a durable intent, rejects completion with 409,
  permits reselling returned stock safely, and eventually marks the original
  cancelled without increasing stock a second time.
- Ten concurrent successful additions retain ten lines; two simultaneous checkout
  requests return one 201 and one 422, with exactly one persisted order. An addition
  started while checkout is paused survives in the cart and is not purchased.
- The 120,000,000.00 case returns 422 without changing inventory's reservation
  count; stock and cart are retained. A preexisting 101-line cart returns 422 without
  RPC, rejects a new line at capacity, and successfully reserves/orders 100 lines
  after removing one.
- A stalled Redis process returned HTTP 200 with X-Catalog-Cache: unavailable in
  549.8 ms on the initial fixed-image run. The final-image regression completed in 548.3 ms and also verifies
  this behavior against a 2.5-second wall-clock bound.
- Existing real HTTP security/workflow, 10-buyer last-unit race, replica-lag and ETag
  demonstration, and forced order-line persistence-failure compensation passed.
  The last-unit race still had one 201, nine conflicts, final stock zero and one order.
- Source built offline using cached layers as
  `3cd941e30be780acdcf39c8d54533cdac1990aee84bcecf04caf7bc84e3b86b3`.
  No new dependencies or images were downloaded for these fixes.
- Independent standards and specification re-reviews found no additional actionable
  defect in these fixes; the follow-up 100-line limit was also reviewed.

Run the new demonstration only after seeding the disposable performance stack:

```fish
env DISPOSABLE_CONFIRM=novacart-perf ADMIN_PASSWORD='DisposableAdminPassword' node docs/performance/verify-deep-fixes.mjs
```

This creates fixture customers/products/orders and temporary SQL fault/delay
triggers, and pauses Redis. Triggers are removed and Redis resumed in finally
blocks. If the process is forcibly killed, restore Redis with
`docker kill --signal CONT novacart-perf_redis_1`, then drop triggers/functions
`verify_fix_cancel` and `verify_fix_cart_delay` in the disposable core database.
Discard only the named performance project's volumes to remove fixtures.

Deployment requires the new migration; the normal app entrypoint applies it
before starting FPM. Recover outstanding intents with `orders:recover-cancellations`
and keep the scheduler running. Recovery requires eventually reachable databases
and inventory. Fixes do not automatically repair orders corrupted before durable
cancellation intents existed; such legacy data needs a separate audit. Product
administration still spans independent stores; no distributed-transaction or
absolute absence-of-bugs guarantee is claimed. Existing Symfony advisories remain
outside these five fixes and were not reassessed.
