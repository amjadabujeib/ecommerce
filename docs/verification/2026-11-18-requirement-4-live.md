# Requirement 4 — live Gmail and browser verification

Subsequent verification: [fresh production images and image-only Gmail/browser recovery](2026-11-18-clean-image-build.md) passed. Build limitations below describe this earlier run.

Verified on 2026-11-18 against a separate `novacart-r4-interview` Compose project. This extends the [automated verification report](2026-11-18-requirement-4.md) with real external-mail receipt and visible GUI evidence. It does not claim a clean production-image build, deployment to a shared environment or course/team sign-off.

## Environment and authorization

The user explicitly authorized opening/inspecting the browser, using the configured project mailbox and completing the tests. All test orders/accounts are in the new isolated project's own PostgreSQL/Inventory/RabbitMQ volumes; existing stopped projects were not started or modified.

No additional packages, browser binaries or images were downloaded. The stack uses cached PHP with sockets/pcntl, cached Inventory/Nginx/PostgreSQL/RabbitMQ images, the normal Compose files and a temporary override mounting the current application read-only. Per-container bootstrap caches are temporary; storage/secrets use the isolated project's volume. This validates the current checkout's runtime and Compose wiring rather than a freshly built production artifact.

Browser automation used the already installed Brave executable and locally cached Puppeteer bundled with Chrome DevTools tooling. Two separate browser contexts/windows exercised the actual GUI. The user's existing Gmail tab was not attached to the automation or changed. Mail receipt was verified through read-only Gmail IMAP using the locally configured app password, searching only the test event's Message-ID and reading only its matching message. No account passwords, JWTs, mailbox addresses or unrelated messages are retained in this report.

The private runtime configuration, temporary Compose override, scripts, screenshots and redacted machine-readable evidence are under `/tmp/novacart-r4-live/`. That directory is outside Git and access-restricted; screenshots may contain the test customer's address. It is temporary and should not be treated as a backup or public evidence bundle.

## Demonstration results

| Scenario | Observation | Result |
| --- | --- | --- |
| Administrator session | Logged in through the GUI and subscribed to `private-admin.orders` through the public Nginx WebSocket proxy | Pass |
| Customer session | Registered a disposable customer using the controlled project mailbox and purchased a seeded catalog item through the GUI | Pass |
| Mail consumer stopped | Stopped only `mail-worker` before checkout; HTTP returned 201, the order persisted, and no mail delivery was processed inline | Pass |
| Admin new-order push | The already-open admin order list displayed order #1 without document reload | Pass |
| Owner status push | Clicking “Mark completed” in the admin GUI changed the already-open customer's order card to Completed without document reload | Pass |
| Mail consumer restarted | Restarted `mail-worker`; its delivery ledger recorded successful Gmail SMTP acceptance for the original creation event | Pass |
| Real inbox receipt | The exact event Message-ID was found in Gmail INBOX; subject, order number, total and line quantity/unit price matched the committed creation snapshot | Pass |
| Channel permissions | Guest authorization returned 401; another customer targeting order #1 returned 403; customer targeting the admin channel returned 403; owner/admin authorization succeeded | Pass |
| Reconnect and transient auth failure | Restarted Reverb and injected one browser-side HTTP 503 for subscription authorization. The open customer GUI retried and resubscribed without reload | Pass |
| Update after recovery | Cancelled the completed order through the admin GUI; the recovered customer page changed to Cancelled without refresh | Pass |

Observed timings in this single local run:

- Checkout response: **107 ms** from the GUI action to HTTP 201.
- Administrator card visible: **234 ms** from the customer placing the order.
- Customer completion visible: **70 ms** from the administrator action.

These are demonstration observations, not a statistically meaningful benchmark or promised SLA. Stable `performance.timeOrigin` values established that the admin/customer documents were not reloaded during the live-update assertions. Actual WebSocket subscription/order frames were observed through browser network instrumentation. No browser runtime exceptions were recorded in the main checkout/status scenario.

Order #1's creation event was `1c75b2d3-a75d-42bd-a985-06412f6b55cb`. Its confirmation arrived once during this run. The order was subsequently completed and then cancelled, demonstrating recovery; the email correctly retains the immutable creation snapshot. This does not establish exactly-once Gmail delivery across the documented SMTP-acceptance/crash ambiguity.

## Previous checks and remaining limits

The earlier real-service harness separately passed transient SMTP retry, four-attempt exhaustion into an actual RabbitMQ DLQ, healthy-message progress, explicit replay, process death before acceptance/ACK, broker storage/recovery and last-unit safety. The fresh source review's two browser-recovery defects were fixed and all 19 browser tests passed before this live run. The live Reverb/auth-failure scenario exercises that recovery through the real GUI as well.

A full clean production Dockerfile build remains unverified. The performance/cache/replica configuration and messaging configuration were tested separately; this run does not prove their combined enabled configuration under paused replay. Shared deployment, teammate PR review and per-member participation remain separate course evidence.

The isolated stack and the two shop browser sessions were left running for the user to inspect at `http://localhost:8080`. No pending real-mail work is expected after this demonstration. The project mailbox credentials remain in the ignored local `.env`; the disposable customer password is in a private temporary file, not this report.

## Recovery and cleanup

The exact startup command prefix is saved privately as `/tmp/novacart-r4-live/compose-command.json`, with the temporary override at `/tmp/novacart-r4-live/override.yml`. The project name is `novacart-r4-interview`; always retain that project/file selection when issuing Compose commands. Root `.env` values are interpolated locally and must not be printed using an unredacted resolved configuration.

Stop this isolated project without deleting its volumes when it is no longer needed. Deleting its volumes permanently discards its orders, stock, inbox state and broker messages and must only follow an explicit decision that the project is disposable. The normal deployment's volumes were not reused. The [operator guide](../runtime/requirement-4.md) explains worker restart, DLQ inspection/replay and cleanup behavior.
