# Requirement 4 implementation design

Status: implemented and locally verified, 2026-11-18; external-email and visible browser demonstrations subsequently verified in the [live report](../verification/2026-11-18-requirement-4-live.md). See the [verification report](../verification/2026-11-18-requirement-4.md). [SPEC.md section 7](../../SPEC.md#7-requirement-4--asynchronous-queues-and-real-time) contains the course acceptance gate; the [lecture](../requirements/requirement-4.md) and [interview checklist](../requirements/requirement-4-interview.md) remain the instructor sources. Decisions below are project choices, not additional instructor demands.

## Selected approach

| Responsibility | Decision |
| --- | --- |
| Durable message broker | RabbitMQ in its own Docker container with a persistent volume and private AMQP port |
| AMQP client | `php-amqplib/php-amqplib`, behind shared infrastructure; explicit publisher confirms and manual consumer acknowledgements |
| Email execution | Separate PHP Artisan consumer in the core app image; Notifications owns the handler |
| Real email | Project-only Gmail account using SMTP and a dedicated app password; account setup, real Gmail receipt and image-only browser demonstration verified locally |
| WebSocket server | Laravel Reverb in a separate process/container using the core app image |
| Real-time delivery | Separate RabbitMQ consumer; independent of the email consumer |
| Browser | Existing static JavaScript GUI, with a pinned Pusher-compatible browser client served locally; no Node application server or frontend rewrite |
| Durable SQL intent | Shared messaging outbox in the core database, written in the same transaction as the corresponding order mutation |

The current Reverb manifest permits Illuminate 13; php-amqplib's manifest requires sockets and mbstring. Dependency resolution selected Reverb 1.14.0 and php-amqplib 3.7.5; manifests, lockfiles, browser integrity metadata and the Compose broker digest record the pins. The cached test PHP image has sockets/pcntl compiled from bundled source. Fresh production Dockerfile builds and image-only deployment now pass; see the [build verification](../verification/2026-11-18-clean-image-build.md). Do not use moving branches as release pins. See the [Reverb manifest](https://raw.githubusercontent.com/laravel/reverb/main/composer.json) and [AMQP client manifest](https://raw.githubusercontent.com/php-amqplib/php-amqplib/master/composer.json).

Using the AMQP client directly keeps acknowledgements, confirms and DLQ behavior explicit; Laravel's existing `QUEUE_CONNECTION=sync` must never execute the new email handler during HTTP checkout. No third-party Laravel queue-driver compatibility is assumed. The generic queue setting can remain for unrelated work.

## Ownership and small interfaces

Orders continues to own order persistence, lifecycle and immutable public order facts. Users owns recipient addresses, roles and authenticated identities. Notifications consumes Orders public facts and owns confirmation templates, delivery records, database inbox updates and broadcast payload construction. Shared messaging infrastructure owns transport, outbox leases and broker topology; it cannot query domain models.

Keep the existing acyclic domain dependency graph. In particular, do not make Orders call Notifications to enqueue mail. Orders records a versioned public event using shared infrastructure inside its transaction; Notifications interprets that event after RabbitMQ delivery. Shared infrastructure routes by configured event type without importing private Orders or Notifications classes.

Proposed seams:

- A transaction-bound `record(event)` operation writes the immutable envelope/outbox entry using the caller's core SQL connection. It must not open an independent transaction or use `primary_control` for this write.
- A publisher drains eligible committed entries and supports a bounded immediate attempt for the event just committed. Callers do not implement connection recovery or confirms.
- Notifications handlers accept validated immutable event values. They do not receive Eloquent orders/users, builders or a transport delivery object.
- Extend Users' public directory with a primary-backed recipient snapshot lookup. Add only the public ownership/authorization operation needed in Orders for channel access. All foreign model/query access remains within its owner.

Existing post-commit synchronous notification listeners must be deliberately replaced or adapted, not left running alongside the new path. Preserve database notification contents/read-state behavior; make writes idempotent by event ID and recipient. They move to the independent notification/real-time path and may become visible shortly after checkout rather than within its response. A committed order must not return a 500 because an inbox, mail or broadcast handler failed.

## Checkout and status flow

1. Authenticate/authorize, lock the cart/products and reserve inventory using the existing synchronous gRPC flow.
2. In the existing order SQL transaction, persist order/lines, clear the cart, record the reservation commitment and insert the immutable order-created event. All local writes commit or roll back together.
3. After commit, attempt confirmed RabbitMQ publication with a total two-second budget, including connect/write/confirm waits. In the healthy path, publish before returning the existing HTTP 201 response. Never wait for Gmail or Reverb.
4. If publication fails or its result is ambiguous, leave the outbox entry recoverable and still return the committed order as 201. Do not compensate inventory, create a second order, or tell the browser to retry checkout because of a notification failure. Record a redacted operational error.
5. The relay retries pending outbox entries. A crash after broker acceptance but before SQL publication marking can publish the same event again; consumers deduplicate using the stable event ID.
6. Independent consumers handle mail and real-time/inbox work. Mail stoppage leaves messages pending without stopping the other consumer.

For status changes, increment the order revision and record the status event inside the locked status transaction. Preserve cancellation intent/release recovery; emit cancellation only when the visible transition commits, including scheduler recovery. Re-submitting the current status creates no new event. Preserve the current status-update HTTP contract even if post-commit publication is unavailable.

A rolled-back order or transition leaves no publishable outbox entry. Test insertion failure as an order persistence failure through the existing compensation path. Catch publication failures only after commit; never swallow reservation or SQL failures.

## Event and persistence contract

Use JSON, not serialized PHP models. Each envelope contains `schema_version=1`, a UUID `event_id`, `type` (`order.created` or `order.status_changed`), UTC `occurred_at`, `order_id`, `order_revision`, server-derived `customer_id`, and immutable order data. The order snapshot includes status/label and decimal-string total; confirmation details use committed line quantities/prices. Do not read current product prices later to reconstruct the purchase. If product titles are included, capture their checkout snapshot through the existing catalog interface. Never include JWTs, credentials or arbitrary client-supplied recipients.

Add an internal monotonic revision to orders: initial creation is revision 1, each real status transition increments it under the existing order lock. Migrate existing rows to a baseline revision without generating historical emails. Expose revision additively where the browser needs to reconcile snapshots. Define and test any additive response fields in Postman; preserve existing fields, error envelopes and status codes.

| Proposed storage | Required data/invariants |
| --- | --- |
| `message_outbox` | Event ID primary key, type/schema/payload, creation/publication timestamps, attempts, next attempt time, bounded lease and redacted last error; index due unpublished entries |
| Notification delivery ledger | Unique `(event_id, handler, recipient_id)` key, attempt count, retry generation, state, provider-acceptance time where applicable, bounded lease and redacted failure history |
| `orders` revision | Monotonic integer changed in the same SQL transaction as the status and outbox entry |

Names are implementation proposals. Outbox/delivery queries use primary. Drain in bounded batches with `FOR UPDATE SKIP LOCKED` or equivalent short claims; release SQL transactions before waiting on RabbitMQ/SMTP. Expired leases allow recovery after process death. Never hold an order lock while waiting for mail. Published-row cleanup is explicit, bounded and excludes pending work. No new per-line catalog/profile queries.

## Broker topology and delivery policy

Use a durable topic exchange `novacart.orders.v1`:

| Queue | Binding / role |
| --- | --- |
| `novacart.mail.v1` | `order.created`; confirmation email only |
| `novacart.realtime.v1` | `order.created` and `order.status_changed`; database inbox updates and browser push |
| Separate retry queues per path and delay | 5 seconds, 30 seconds and 120 seconds; TTL expiry routes back to that path's work queue, never fan-outs again to both handlers |
| `novacart.mail.v1.dlq`, `novacart.realtime.v1.dlq` | Terminal messages retained for explicit inspection/replay |

Declare topology before serving traffic. Use persistent messages, manual acknowledgements, initial prefetch 1, publisher confirms, and mandatory routing checks. A confirm alone cannot prove that *both* expected bindings exist: provisioning/health checks must verify those bindings. Never mark the outbox published after a nack, returned unroutable message or confirm timeout. Authentication/topology errors surface as operational failures, not an infinite silent loop.

Default delivery policy: one initial attempt and three retries, after 5/30/120 seconds; `MESSAGING_RETRY_DELAYS` permits three positive increasing delays for isolated tests. SMTP socket operations have a finite 15-second timeout; AMQP heartbeat/read-write settings must tolerate that processing duration. Transport connection recovery uses bounded backoff. Test shutdown both between jobs and during a send.

On success, persist the handler result before acknowledging. On failure, persist the attempt and use confirmed publication to the appropriate retry destination before acknowledging the original; terminal failures transfer to the DLQ using the same rule. This acknowledgement means durable transfer of failed work, **not successful email delivery**. Never acknowledge a failed send without recoverable work elsewhere. If transfer is ambiguous or fails, leave the source unacknowledged and recover through channel closure/backoff. Stable retry generations and the ledger must prevent duplicate copies from exhausting attempts or sending concurrently. Malformed/unsupported events go to quarantine/DLQ with a redacted reason.

Use quorum queues and explicitly configure at-least-once dead-lettering for TTL retry queues, with the required overflow policy and a declared destination. Verify these settings against the pinned RabbitMQ release; do not rely on default DLX safety. A single broker remains a single-host failure point: its persistent volume proves restart recovery, not high availability. RabbitMQ distinguishes [consumer acknowledgements from publisher confirms](https://www.rabbitmq.com/docs/confirms), and documents [DLX safety limitations](https://www.rabbitmq.com/docs/dlx#safety) and [quorum dead-lettering requirements](https://www.rabbitmq.com/docs/quorum-queues#dead-lettering).

The ledger prevents repeat sends after recorded success. It cannot eliminate a duplicate if Gmail accepted the message and the worker died before recording success. Use a stable mail Message-ID for correlation, but do not claim Gmail deduplicates it. Record this ambiguity during the interview. An SMTP acceptance response proves provider acceptance; the joint live check must also verify arrival in the controlled inbox/spam folder.

DLQ inspection must not consume/drop work by accident. Replay is an explicit operator action after correcting the cause; preserve the original event ID and record a new replay generation. Reject replay of already-successful mail by default. Never bulk replay against uncontrolled addresses.

## WebSockets, authentication and UI

Run Reverb as the existing server implementation, with exact allowed browser origins and a reverse-proxied same-origin WebSocket endpoint. Only the app key and public connection settings reach JavaScript; signing secrets remain server-side. Use an authenticated versioned subscription-authorize endpoint integrated with the existing JWT guard, refresh and primary-only blacklist checks. See [Reverb configuration](https://laravel.com/docs/13.x/reverb).

Two logical channel types:

- `private-orders.{orderId}`: authorize only the current owner using primary-backed Orders authorization. Deny guests and other customers. Admin operations remain on the admin interface.
- `private-admin.orders`: authorize only current administrators using authoritative Users identity/roles.

Publish `order.created` to the admin channel; publish `order.status_changed` to the owner's order channel. Channel payloads contain only the fields needed by that audience. Do not broadcast customer email addresses or full inbox contents. Batch any needed admin-card profile enrichment through Users' public directory.

The static GUI subscribes when the relevant view opens, unsubscribes on navigation, and disconnects/clears protected state on logout or account change. Integrate token rotation with the existing API client's session-generation handling: obsolete callbacks cannot subscribe or render data for a previous session. A revoked/expired token cannot authorize a new subscription. Existing socket authorization does not automatically rerun on JWT expiry/revocation; implementation must provide and test a server-enforced disconnect/revalidation mechanism before claiming continued private-channel access is revoked. Browser logout alone does not prove that a deliberately retained socket lost access.

Render idempotently by order ID/event ID and accept only newer order revisions. On reconnect, resubscribe first, buffer arriving events, load an authorized primary-backed snapshot, then merge by revision. The same catch-up handles initial subscription races. Transient subscription/configuration failures retry with a single session-fenced timer and exponential backoff capped at 30 seconds; permission denials do not retry automatically. A session-specific catch-up coordinator prevents old failures from blocking a new login and drains requests queued behind a failed load. Normal live operation uses push, with no recurring HTTP status polling. Existing paginated lists retain filters/sorting: show or merge a new matching order without duplicating cards or silently corrupting pagination totals. A primary-backed catch-up mode must be narrowly scoped to authorized order views; avoid changing all GET requests to primary.

A broadcast accepted by Reverb is not proof that every browser received it. Reconnect catch-up is mandatory. The mail consumer never performs or gates these broadcasts. Within the real-time consumer, deduplicate inbox writes separately from broadcast progress so a retried broadcast cannot duplicate database notifications.

## Email configuration and joint live check

The user will create a project-only email account. No credential is needed for design or offline handler tests. For Gmail, enable two-step verification and generate a dedicated app password if the account supports it; the normal account password is not the SMTP credential. Keep both outside chat/tracked files. Google's [app-password instructions](https://support.google.com/accounts/answer/185833) describe eligibility restrictions and revocation on account-password changes.

Proposed local-only configuration (placeholders, not working credentials):

```dotenv
MAIL_MAILER=smtp
MAIL_SCHEME=smtps
MAIL_HOST=smtp.gmail.com
MAIL_PORT=465
MAIL_USERNAME=project-account@gmail.com
MAIL_PASSWORD=local-app-password
MAIL_FROM_ADDRESS=project-account@gmail.com
MAIL_FROM_NAME=NovaCart
MAIL_TIMEOUT=15
```

`MAIL_TIMEOUT` requires wiring into the existing Laravel mail configuration; adding an environment variable alone is insufficient. Google documents [SMTP TLS ports](https://developers.google.com/workspace/gmail/imap/imap-smtp). Inject credentials into the mail worker only where possible. Verify that Compose actually forwards local configuration into that process; an ignored `.env` file alone is not proof. Never print resolved secret-bearing Compose configuration or raw SMTP authentication logs into reports.

Use a registered disposable customer whose server-side email is a controlled inbox; the project account can also be the recipient. Keep an explicit recipient allowlist in the demonstration environment to prevent fixture addresses receiving mail. Report an attempted blocked recipient as a delivery failure, never a successful send. This is a test-environment control, not a client-controlled checkout email override.

Together we will configure the local credential, verify a single real confirmation, and then run the stopped-worker/restart, retry/DLQ and two-browser demonstrations. Record recipient/provider evidence redacted. The local SMTP fixture has exercised sending without external network access; no real Gmail send has occurred.

## Containers, dependencies and rollout

Add RabbitMQ, outbox relay, mail worker, real-time worker and Reverb processes. Reuse the core image for PHP processes. Keep the existing scheduler responsible for reservation/cancellation recovery; don't replace it with an email worker. Initialization/migrations/topology provisioning must complete before consumers start. On subsequent broker disconnects, workers reconnect without relying only on Compose startup ordering.

RabbitMQ management access, if enabled for the interview, binds to loopback with separate local credentials. AMQP stays on the internal network. Preserve existing database/stock volumes and use a separate Compose project with disposable fixtures for failure demonstrations. Set resource limits appropriate for the development laptop; no multi-broker cluster is required for requirement 4.

Before dependency installation/image pulls, inspect cached layers and calculate the missing compressed download estimate. Obtain the required authorization for downloads and service startup. The existing cached-image test authorization does not authorize downloading RabbitMQ or new Composer packages. Host PHP/Node package installation is unnecessary for this design.

Implementation order:

1. Add transaction-bound event snapshots/revisions/outbox and failure tests; preserve inventory compensation and cancellation recovery.
2. Add RabbitMQ topology and confirmed relay; prove rollback, broker outage and ambiguous-publication recovery.
3. Add independent Notifications mail consumer, idempotent ledger, retry and real DLQ; exercise SMTP through a controlled test adapter before live mail.
4. Add independent inbox/real-time consumer, Reverb and authoritative channel authorization.
5. Add GUI subscriptions, session handling, revision merge and primary catch-up; update Postman and startup/operator documentation.
6. Run architecture/HTTP/browser regression checks and real disposable PostgreSQL/gRPC/RabbitMQ tests. Then perform the live Gmail and interview checks together.

Do not claim completion after the offline phase. Collect evidence for every R4-01–08 and D4-01–05 in a new requirement 4 verification report: exact pinned versions, topology/policies, executable worker/start/recovery commands, measured delays, delivery IDs, redacted failures, authorization results and cleanup. Add assertions for rollback/no event, broker outage/201 plus eventual delivery, duplicate publication, consumer death, poison-message isolation, reconnect ordering, lagged replica, JWT revocation and cross-customer subscription denial. Repeat applicable last-unit/cancellation/cache/replica regressions. Leave unsent email, unrun browser demonstrations or unavailable dependencies explicitly pending.

## Reverb service-address recovery

The long-running broadcaster disables libcurl DNS caching for its Reverb client (`CURLOPT_DNS_CACHE_TIMEOUT=0`), retaining the bounded connect/request deadlines. A replaced Reverb container can receive a new address; subsequent broadcasts must resolve it without restarting the worker. The real-service harness retains one broadcaster across a deterministic address change and verifies successful delivery. See the [build/recovery evidence](../verification/2026-11-18-clean-image-build.md).
