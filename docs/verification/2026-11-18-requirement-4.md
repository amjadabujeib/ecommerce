# Requirement 4 implementation verification — 2026-11-18

Subsequent verification: [fresh production images and image-only Gmail/browser recovery](2026-11-18-clean-image-build.md) passed. Build limitations below describe this earlier run.

Status: implementation and automated local checks passed. A subsequent [live verification report](2026-11-18-requirement-4-live.md) now records real Gmail inbox receipt and the visible two-browser demonstration. Historical pending statements below describe this automated phase unless explicitly updated; shared deployment/course sign-off and a clean production-image build remain separate.

## Checkout and environment

Work is on `feat/requirement-4-async-realtime`, based on `3bc965a`, with changes uncommitted at the user's request to implement directly from the spec. No existing deployment, database volume or mail account was changed. The [specification](../../SPEC.md#7-requirement-4--asynchronous-queues-and-real-time), [implementation design](../design/requirement-4-implementation.md), and [operator guide](../runtime/requirement-4.md) describe the contracts and pending interview steps.

The user authorized downloads up to 120 MiB and isolated disposable containers. RabbitMQ's pinned amd64 image is 90.25 MiB compressed; the Pusher archive is 1.49 MiB (fetched twice during license extraction). Composer installed the additional locked packages. No model weights or host packages were installed. PHP sockets/pcntl were compiled from bundled source using cached images without extension-build downloads. Later verification used `--pull=never` and internal networks. The cached test image `localhost/novacart-r4:local` mounts this checkout and its resolved vendor directory; a full clean production Dockerfile build was not performed.

Pinned additions: RabbitMQ `4.4.0-management-alpine` amd64 digest `sha256:598b474e2511f37a7408f3fa580f002684638f4ad6401395102b51e3209a9a51`; Reverb `1.14.0`; php-amqplib `3.7.5`; Pusher JS `8.6.0`. Laravel remains `13.39.0`; the only updated existing package is Symfony HttpFoundation `8.1.9` → `8.1.11` during security-aware dependency resolution. The dependency audit still reported five advisories in existing packages; this milestone did not establish their exploitability or complete a separate dependency-remediation task.

## Checks and results

| Check | Result |
| --- | --- |
| Module dependency guard | Pass; domain references use public interfaces and the established acyclic graph |
| Laravel suite | 156 tests, 736 assertions passed, including outbox rollback/compensation, broker-failure response, revisions/no-op changes, delivery/replay, primary recipient lookup, channel authorization, Reverb delivery and pruning |
| Browser-client suite | 19 tests passed: token/session coordination, revision deduplication, subscription-first catch-up, transient authorization recovery, retry cancellation/backoff and session-isolated reconciliation |
| Real requirement 4 integration | Pass against disposable PostgreSQL, gRPC Inventory, RabbitMQ, Reverb and a local SMTP protocol fixture; scenarios below |
| Prior cache/replica integration | Pass after adapting its migration rollback target for the newly added migrations; real PostgreSQL/Redis/gRPC, paused replay/authentication, cache-fill races, last-unit race and persistence compensation/outage scenarios |
| Configuration/assets | Compose overlay parsing, Nginx syntax, isolated Reverb startup, locked dependency installation and local browser asset integrity checked |
| Documentation/contracts | Real-time Postman collection added; order revision documented; race demo asserts initial revision; local Markdown links, JSON and JS syntax checked |

The Laravel runner executes inside the cached PHP image with temporary storage/bootstrap-cache directories and SQLite for HTTP behavior checks. These checks alone do not establish distributed correctness; the two separate real-service harnesses provide that evidence.

Run the browser suite from the repository root:

```fish
node --test tests/browser/*.test.mjs
```

Run the existing architecture and Laravel checks inside a prepared PHP runtime from `laravel-app/`:

```fish
php tests/Architecture/check-modules.php
php artisan test --compact
```

The service-mutating harnesses require explicit authorization and disposable cached images:

```fish
env DISPOSABLE_CONFIRM=requirement-4 node docs/demo/verify-requirement-4.mjs
env DISPOSABLE_CONFIRM=cache-routing node docs/performance/verify-cache-routing.mjs
```

The requirement 4 harness uses Node 26.13.1's WebSocket support with a controlled Origin header, not an automated GUI browser. It provisions an internal network, PostgreSQL core/inventory databases, real Inventory gRPC, RabbitMQ, a PHP HTTP process, Reverb and a local-only SMTP fixture. It removes its uniquely named containers/network in `finally`; it does not pull images or use production secrets.

## Real-service scenarios

- An order commits and returns 201 while no mail consumer is running. The outbox event is confirmed into RabbitMQ. The independent real-time consumer sends an actual admin WebSocket event and updates database notifications.
- An owner subscribes to their order; another customer and a non-admin cannot authorize foreign/admin channels. Status transitions arrive over the genuine private owner WebSocket.
- The same open socket is retained after logout. Reverb checks JWT blacklist and ownership/role on primary at delivery and denies the next private status payload. Separate real Reverb channel tests cover expiry and administrator demotion too.
- The mail consumer speaks SMTP to the local protocol fixture and records acceptance before ACK. Republishing the same order event produces no second accepted send and no duplicate database inbox entries.
- RabbitMQ is stopped before checkout publication. The already committed purchase still returns 201 and keeps its pending outbox event. A previously confirmed message also remains queued across that broker restart. Restarting the same broker and draining the relay recovers publication and both deliveries; the order is not recreated and stock is not compensated for notification failure.
- A controlled SMTP failure transfers to a real durable TTL retry queue and later succeeds. A permanent failure exhausts four processing attempts and appears in the actual RabbitMQ DLQ. A later healthy message succeeds; explicit replay recovers the terminal message after removing the fault.
- A consumer is killed while waiting for SMTP acceptance, before ACK. The source remains recoverable. The test explicitly ages its SQL processing lease, then redelivery succeeds. This validates the recovery path; the normal 90-second lease duration is not waited out in the harness.
- With messaging enabled, two customers race for one unit: exactly one 201, one 409, one new persisted order/outbox event and final stock zero. The 409 response is checked, not merely ignored.

Default retry delays are 5/30/120 seconds. The disposable harness overrides them to 1/2/3 seconds. SMTP acceptance followed by process death before the ledger write remains ambiguous and can duplicate an email; a stable Message-ID is correlation, not a Gmail exactly-once guarantee.

## Review fixes and test-harness corrections

Independent standards/spec source reviews found no new module-ownership or inventory-authority violation. Two actionable issues were corrected:

1. Root `vendor/` ignore rules initially excluded the pinned Pusher asset. Narrow exceptions now retain only the owned browser runtime directory, including integrity metadata and licenses.
2. RabbitMQ's own delivery limit can dead-letter work before SQL marks it terminal. Explicit replay now reconciles a matching physical DLQ message with an absent/interrupted ledger generation, rejects active leases and already-successful sends, and retains the stable event identity. Behavior tests cover this recovery and its refusal cases.

Integration preparation also corrected Reverb's `accept_client_events_from` type to supported `'none'`, used a real recording socket adapter where PHP 8.5 mock generation could not reproduce an upstream typed default, and made disposable PostgreSQL readiness wait on TCP rather than its temporary initialization socket.

The existing cache/routing harness initially failed because it assumed the catalog migration was the newest migration (`--step=1`). It now counts migrations through that exact catalog migration, rolls back through that target, checks legacy trigger restoration, and reapplies all pending migrations. The rerun passed. This change adjusts the test for the new milestone's migrations rather than restoring catalog versioning in the application.

## Follow-up review fixes — browser recovery

A fresh review found two additional P2 browser recovery defects. Both were reproduced before the fix:

- A temporary subscription authorization failure left an otherwise connected page unsubscribed. The client now retries transient failures with a single timer and exponential delays capped at 30 seconds, reconnects using the current token, and performs primary catch-up after subscription succeeds. Permanent permission denials do not trigger retries. Logout, account/channel changes and token rotation cancel obsolete retries; partial subscription success cannot cancel another channel's recovery.
- A failing old-session catch-up could strand a new session's queued reconciliation. The GUI now uses a session-specific catch-up coordinator, reset with private views. A new session drains independently; old-session failures cannot overwrite the new coordinator or display obsolete errors. Requests queued behind a same-session failure are still drained.

Seven added browser regressions cover the reported failures, capped backoff, logout/account-switch cancellation, permission-denial handling, partial resubscription and queued catch-up after failure. All 19 browser tests and JavaScript syntax/diff checks passed. No PHP, broker, inventory or mail behavior changed in this follow-up; the earlier backend results above were not rerun for these browser-only fixes. Real GUI/Gmail interview verification remains pending.

## Outstanding joint gate and limits

- Configure the project-only Gmail account/app password in local configuration and a controlled registered recipient; do not put credentials in chat or tracked files. Verify real external-provider acceptance and actual inbox/spam arrival.
- Open customer/admin GUI sessions together and record visible no-refresh new-order/status updates, restart recovery, subscription denial, and measured end-to-end delays. Network WebSocket automation and browser helper tests do not replace this visible interview demonstration.
- A full production-image rebuild and complete Compose deployment/upgrade were not run. Startup configuration, source-mounted runtime behavior, migrations and service contracts were checked separately.
- Prior cache/replica checks ran with messaging disabled, while the RabbitMQ/WebSocket harness ran with replica routing disabled. Their combined enabled deployment remains a useful joint check; primary-backed catch-up and authorization are implemented but this report does not claim that combined configuration was exercised under paused replay.
- A single persistent RabbitMQ node supports restart recovery, not host-loss high availability. No new performance throughput or latency benchmark is claimed.

The baseline synchronous deployment remains available with messaging disabled. For the optional stack, use the exact project/file arguments in the operator guide when stopping services. Do not remove volumes with pending work. Prune only confirmed old outbox publications; retain delivery identities while broker copies/replays can exist. No commits, pushes or deployment changes were made.
