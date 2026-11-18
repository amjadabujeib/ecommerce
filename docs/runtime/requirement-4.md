# Requirement 4 runtime and interview guide

The [implementation design](../design/requirement-4-implementation.md) explains the delivery contracts. This guide describes the optional runtime; the [verification report](../verification/2026-11-18-requirement-4.md) records the automated evidence. Real Gmail delivery and the visible two-browser demonstration are recorded separately in the [live verification report](../verification/2026-11-18-requirement-4-live.md).

For another machine and reuse of the shared project Gmail sender, follow the [teammate setup guide](teammate-testing.md).

## Dependencies and configuration

The base `docker-compose.yml` continues to run the requirements 1–3 deployment with messaging disabled. Add `docker-compose.realtime.yml` to enable RabbitMQ, the publication relay, independent mail/real-time workers and Reverb. The PHP workers reuse the core image. They do not replace the existing reservation/cancellation scheduler.

Pinned dependencies are recorded in `laravel-app/composer.json`/`composer.lock`; the browser asset version, npm archive integrity and SHA-256 are in `laravel-app/public/assets/vendor/pusher-manifest.json`. RabbitMQ is pinned by the overlay to its amd64 image digest, with a persistent `rabbitmq-data` volume. The Dockerfile builds PHP sockets and pcntl from its bundled source alongside pdo_pgsql. No host PHP/Node installation is needed.

After downloading/building dependencies under the applicable authorization, check the dependency installation using the actual PHP runtime; Composer's build stage ignores platform requirements because the native gRPC extension is installed in the final runtime.

Create a local root `.env` for Compose interpolation (it is ignored by Git). Set independent random `RABBITMQ_PASSWORD` and `REVERB_APP_SECRET` values; never reuse account/JWT passwords. `REVERB_APP_KEY=novacart-local` is a public application identifier, not the signing secret. The overlay refuses to resolve without the two secret variables. Keep `REVERB_ALLOWED_ORIGINS` to exact browser hostnames; the local default permits localhost and 127.0.0.1. Update `APP_URL`, `REVERB_PUBLIC_HOST` and `REVERB_PUBLIC_SCHEME` when using another hostname or HTTPS.

For the joint real-email test, set:

```dotenv
MAIL_SCHEME=smtps
MAIL_HOST=smtp.gmail.com
MAIL_PORT=465
MAIL_USERNAME=project-account@gmail.com
MAIL_PASSWORD=local-app-password
MAIL_FROM_ADDRESS=project-account@gmail.com
MAIL_RECIPIENT_ALLOWLIST=controlled-recipient@gmail.com
```

Use the project-only Google account's dedicated app password, not its normal password. Google's [app-password instructions](https://support.google.com/accounts/answer/185833) explain two-step verification and account restrictions. The overlay injects SMTP credentials only into `mail-worker`; the HTTP app, Reverb, relay and real-time worker do not need them. The controlled recipient must be the server-side address of the disposable test customer. The allowlist is required by this demonstration overlay; blocked recipient attempts are failures, not successful deliveries.

Do not print `compose config`, container environments or resolved mail configuration into reports: those outputs can contain secrets. Changing a root `.env` value does not update a running process until it is recreated. Config cached into an image/container can override later environment values: keep worker config cache separate from app runtime caches, and verify the intended process receives its own configuration without logging secret values.

## Startup

The commands below use Docker Compose. The repository also supports the local Podman Compose provider where available; preserve the same project/file arguments. Starting services, pulling images and changing service availability require the applicable task authorization.

Use a separate project name and disposable fixture accounts for tests. Never reuse a live project's database volumes for failure injection. Example from the repository root:

```fish
docker compose -p novacart-r4 -f docker-compose.yml -f docker-compose.realtime.yml up -d --build
```

The app performs migrations before its health check becomes healthy. `messaging-setup` then waits for RabbitMQ and declares exchanges/queues before the relay/consumers start. Reverb waits for the app; the real-time worker waits for Reverb. Workers also reconnect after later outages; startup ordering alone is insufficient.

Nginx proxies browser WebSocket `/app/` requests to `reverb:8081`, preserving upgrade headers. The signed Reverb publishing interface remains internal and is not proxied under `/apps/`. There is no published AMQP or Reverb port. Management is available at `http://127.0.0.1:15672` using the local RabbitMQ account; change `RABBITMQ_MANAGEMENT_PORT` if occupied. Do not expose management or AMQP publicly.

Operational commands inside the app image:

```fish
php artisan messaging:setup
php artisan messaging:relay --once
php artisan messaging:consume mail --once
php artisan messaging:consume realtime --once
php artisan messaging:dlq mail
php artisan messaging:prune --days=7 --limit=1000
```

Pruning removes only old confirmed outbox publications in bounded batches; pending events and delivery identities remain. Keep delivery identities for deduplication while broker copies/replays may exist. Expired encrypted subscription credentials are pruned hourly by the existing scheduler.

The long-running processes omit `--once`. `QUEUE_CONNECTION=sync` is not the delivery mechanism: independent AMQP consumers perform these handlers. Default retries are 5, 30 and 120 seconds after the first failed attempt. See the messaging configuration for bounds/timeouts. An acknowledged retry transfer means the failed work was durably handed off, not that Gmail accepted an email. Quorum retry queues explicitly enable at-least-once dead-lettering and `reject-publish` overflow. Queue arguments cannot safely change in place: drain/back up pending messages before any topology migration requiring recreation.

## Interview sequence

1. Open an administrator GUI and a separate customer browser/session. Keep RabbitMQ and the real-time worker running; stop only the mail consumer before creating disposable orders.
2. Observe HTTP 201, persisted orders, pending mail messages and immediate new-order updates in the already-open admin browser.
3. Restart the mail worker and verify actual email arrival in the controlled inbox/spam folder. Correlate event/order IDs and redacted provider-acceptance evidence.
4. Change an order status from the administrator view. The owning customer's already-open tracking view must update without refresh or periodic REST polling. Inspect the WebSocket connection/event in browser developer tools.
5. Exercise bounded transient/permanent delivery failures in the disposable environment. Inspect the actual RabbitMQ retry and DLQ queues. Do not delete messages just to inspect them; use the management interface's requeue option or a non-destructive command.
6. Test guest, another customer, owner and administrator channel authorization. Also check retained sockets after token expiry/revocation; rejected new subscriptions alone do not establish existing-socket revocation.
7. Record reconnect catch-up, duplicate event handling, broker restart and publication recovery. A stopped broker must leave a committed order successful with durable pending publication intent.

During an authorized stopped-worker demonstration:

```fish
docker compose -p novacart-r4 -f docker-compose.yml -f docker-compose.realtime.yml stop mail-worker
docker compose -p novacart-r4 -f docker-compose.yml -f docker-compose.realtime.yml start mail-worker
```

Do not kill a worker during a real Gmail send casually: Gmail may accept the message before the worker records that success. A crash in that interval can cause a duplicate after restart; stable Message-ID is for correlation and does not promise Gmail deduplication. Use a controlled failure point and recipient to explain this limit.

## Recovery and cleanup

Broker restarts retain durable messages in `rabbitmq-data`; outbox entries bridge a commit/publication crash and are recovered by the relay. Never delete pending outbox entries, the delivery ledger or broker storage to clear an error. Check redacted logs and correct credentials/connectivity/topology first. DLQ replay is explicit after fixing the cause, keeps the original event identity and must reject already-successful mail by default.

Stop the isolated project when finished:

```fish
docker compose -p novacart-r4 -f docker-compose.yml -f docker-compose.realtime.yml down
```

This keeps its named volumes for recovery. Only after confirming the project is disposable and evidence is recorded, `down --volumes` removes its database, storage and RabbitMQ volumes. It permanently discards pending work and must not target an existing deployment. Revoke the Google app password after the course/demo if no longer needed.


## Automated disposable checks

After explicit service authorization and with the required images already cached:

```fish
env DISPOSABLE_CONFIRM=requirement-4 node docs/demo/verify-requirement-4.mjs
```

This uses a uniquely named internal Podman network and removes its own containers in `finally`. The harness requires a Node runtime supporting WebSocket request headers (tested with Node 26.13.1). Its SMTP fixture never forwards externally. It tests real PostgreSQL/gRPC/RabbitMQ/Reverb connections, owner/admin authorization and revocation, duplicate suppression, broker outage/recovery, actual retry/DLQ/replay, and consumer death before acknowledgement. Test retry delays are 1/2/3 seconds rather than the runtime's 5/30/120; the SQL lease is explicitly aged for crash recovery. This does not replace the joint Gmail/two-browser interview checks.

The CLI names are `messaging:setup`, `messaging:relay`, `messaging:consume mail|realtime`, `messaging:dlq mail|realtime`, and `messaging:prune`. Explicit terminal replay uses `php artisan messaging:dlq mail --replay=EVENT_UUID`; it requires a physical matching DLQ message, preserves the event identity, records a new replay generation and rejects already-successful sends. Inspecting counts does not consume messages. Replay scans at most 1000 ready DLQ messages per invocation and requeues unmatched messages; it never silently purges the queue.

## Verified image build and Reverb recovery

[Fresh Dockerfile builds and image-only Gmail/browser tests](../verification/2026-11-18-clean-image-build.md) pass. The broadcaster resolves Reverb afresh on each request, so a restarted/replaced Reverb container can change address without leaving a long-lived worker on its old IP. Transient connection failures still follow the normal bounded retry policy. When updating images, inspect the running image IDs; if your Compose provider retains an old container, a full `down` without `--volumes` followed by `up -d --no-build` retains data and replaces the containers. This interrupts that project while it restarts.
