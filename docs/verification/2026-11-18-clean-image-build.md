# Fresh production images and portable handoff

Verified on 2026-11-18 using the actual checked-in Dockerfiles and an isolated `novacart-r4-clean` Compose project. This extends the [automated checks](2026-11-18-requirement-4.md) and [earlier live demonstration](2026-11-18-requirement-4-live.md). The [teammate guide](../runtime/teammate-testing.md) provides the reproducible setup and shared Gmail sender procedure.

## Build and deployment

All three Dockerfiles were built with `--no-cache` and cached base images (`--pull=never`). Composer/npm locked dependencies and Debian build dependencies were downloaded through a capped counting proxy: **55,559,609 response bytes (52.99 MiB)**, below the additionally authorized 100 MiB budget. This is the proxy's conservative payload accounting, not an exact ISP traffic measurement. No host PHP/Composer installation was needed.

| Artifact | Final image SHA-256 |
| --- | --- |
| PHP core, reused by workers/Reverb | `8a9ff774c95d5c700734a0c705f7436ca89849d0db1c2cdfe696a076e2e11096` |
| Nginx web | `4ca9c266546b2bf66d44bd6595b29a37b1e65c57ac69a0fe53900b43750dce13` |
| Inventory | `db176af513a141f7e9cfddfa4f9f52f320bb5e66a83f4afc4ee139cf880a253d` |

The normal Compose files plus an image-tag/port override booted the application, scheduler, topology provisioner, relay, mail worker, real-time worker, Reverb, Nginx, Inventory, both PostgreSQL databases and RabbitMQ. Migrations, default catalog/admin initialization and runtime-secret initialization succeeded. All eight long-running application containers were inspected after the final deployment: their image IDs match the built artifacts and none has a host bind mount. Dependencies/source are inside the images; data uses isolated named volumes.

An initial selective Podman Compose recreation retained an older application container despite returning success. An image-ID audit caught this. A full `down` **without deleting volumes**, followed by `up -d --no-build`, deployed all final images and retained test accounts/orders. This is why a successful Compose command alone was not used as artifact evidence.

The bundled Pusher asset is present in PHP and web images and matches its integrity manifest (`91640c608080a550d7a7407f943fa8429e9f6d7d567d0bb810f5f6e73b06972a`). The PHP image contains grpc, pdo_pgsql, sockets, pcntl and mbstring; `composer check-platform-reqs --no-dev` passes. Local `.env`, generated runtime keys, development Faker and build-proxy environment variables are absent from production images.

## Tests and observed behavior

| Verification | Result |
| --- | --- |
| Module boundary check and Laravel suite | Pass; **156 tests / 736 assertions** |
| Browser-client unit suite | Pass; **19 tests** |
| Fresh Inventory image against disposable PostgreSQL schemas | Pass; **15 tests** |
| Real distributed requirement 4 harness | Pass with PostgreSQL, gRPC Inventory, RabbitMQ, Reverb and local SMTP; includes address-change regression below |
| Image-only deployment and persistence | Pass; initialized normally and retained accounts/orders through container replacement |
| Visible admin/customer GUI and real Gmail | Pass on the final production images; results below |

The final GUI run created order **3**, event `5c4e4383-491b-45eb-a28d-fb52b74be757`. With only the mail worker stopped, checkout returned **201 in 207 ms** and the already-open administrator order list showed it **421 ms** after checkout began. Admin completion reached the customer's open order view in **125 ms**. Both views retained their original page time origin, and browser protocol traces showed actual private WebSocket subscriptions/events. No JavaScript page errors were recorded. These are single local observations, not a performance benchmark or SLA.

After restarting the mail worker, the delivery ledger recorded provider acceptance. A read-only IMAP search scoped to the event's exact Message-ID found the actual message in Gmail **INBOX**. Subject, order ID, total, line quantities and unit prices matched the immutable order event. Credentials, mailbox addresses, tokens and customer passwords are excluded from this report.

The running deployment rejected guest subscriptions (401), foreign-owner subscriptions (403) and customer access to the admin channel (403); authorized owner/admin subscriptions succeeded (200). Reverb was then stopped/started, and one browser subscription authorization response was deliberately replaced with 503. The browser reconnected, retried authorization and restored all channels. A subsequent cancellation reached the existing customer page without reload; the complete recovery check took about 3.3 seconds.

## Fixed restart regression

The first fresh-image demonstration exposed a genuine recovery delay: the long-running real-time worker kept Reverb's old container address after Reverb restarted. A retained broadcaster failed with cURL 28 while a newly constructed client immediately succeeded. Earlier status delivery took approximately 155 seconds through retries.

The Reverb HTTP client's `CURLOPT_DNS_CACHE_TIMEOUT` is now **0**, so successive requests resolve the current service address. This is scoped to the broadcasting client; connect/request deadlines remain bounded. [libcurl documents the option and its default 60-second cache](https://curl.se/libcurl/c/CURLOPT_DNS_CACHE_TIMEOUT.html).

`docs/demo/reverb-address-check.php` and the extended `verify-requirement-4.mjs` retain the same actual broadcaster across a deterministic Reverb address change, then require another successful broadcast. The test is guarded for a disposable testing database and sends only an empty probe to an unsubscribed private channel. The unfixed retained-client probe failed; the fixed probe, extended real-service harness and final image-only browser recovery all passed. The PHP/web images were rebuilt after the fix.

## Portability, handoff and remaining limits

The root `.env.example`, explicit amd64 service platforms, LF rules for shell/build files and the [teammate setup guide](../runtime/teammate-testing.md) make the deployment reproducible without the author's local dependency directories. The guide covers same-sender Gmail testing with either a teammate-controlled recipient or the shared project inbox. App passwords travel privately, preferably one revocable password per machine; they are never part of the source archive.

The source handoff ZIP includes the current uncommitted implementation, both environment templates, the shared proto, the compiled gRPC extension and the vendored browser client. It excludes real `.env` files, generated keys, host dependency directories and Git internals. Archive integrity and a scan for the configured local credential/mailbox values pass. Every extracted source file matches the checkout; the extracted Compose manifests resolve successfully. This checks the handoff contents without claiming a second-machine execution.

Verified host: x86-64 Linux with rootless Podman/Compose and a visible Brave browser. Docker Engine/Desktop on Intel Windows/macOS is documented but not executed here. ARM/Apple Silicon needs amd64 emulation for the bundled PHP extension and pinned broker image; native ARM PHP and emulated execution remain unverified. The manifests/lockfiles are authoritative; no bit-for-bit reproducibility across future dependency repositories is claimed.

The combined enabled cache/paused-replica/messaging configuration was not rerun as one deployment. Earlier cache/replica regressions are recorded separately. Shared deployment, teammate review and per-member course participation remain separate obligations.

The image-only stack is left available at `http://localhost:8081` (RabbitMQ management `http://localhost:15673`). The earlier source-mounted interview stack uses port 8080. Their volumes are separate. Stop a stack without `--volumes` to retain orders, stock, keys and pending work; consult the operator guide before deliberately deleting test data. Private test logs/screenshots remain outside the repository in a mode-0700 temporary directory.
