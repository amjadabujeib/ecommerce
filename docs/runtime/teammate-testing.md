# Teammate setup and shared-email test

Use this guide with a checkout/archive containing the requirement 4 implementation. The [fresh image-only build/test report](../verification/2026-11-18-clean-image-build.md) records the verified artifacts and platform limits; the [operator guide](requirement-4.md) explains workers and recovery. A Git clone cannot contain uncommitted work: obtain the updated reviewed branch once published, or a prepared source archive that excludes local credentials and dependency directories.

## Machine prerequisites

Install Docker Engine with the modern Docker Compose plugin, or Docker Desktop on Windows/macOS. Docker Desktop must run Linux containers. Host PHP, Composer, Node, PostgreSQL and RabbitMQ installations are unnecessary. Run commands from the extracted repository root.

Use Compose 2.17 or newer and a builder supporting additional build contexts; the Inventory Dockerfile consumes the checked-in shared `proto/` directory through `additional_contexts`. See [Docker's build specification](https://docs.docker.com/reference/compose-file/build/#additional_contexts).

The PHP gRPC binary and pinned RabbitMQ image target Linux amd64. Their services explicitly select `linux/amd64`. Native x86-64 Linux is verified locally. Intel Windows/macOS with a Linux-container runtime use the same images, but those host OSes have not been tested here. ARM/Apple Silicon requires working amd64 emulation; native ARM PHP is not supported by the bundled extension and emulated ARM execution has not been verified. PostgreSQL, Nginx and Inventory use the platform selected by their multi-platform base images.

A first setup can download several hundred MiB (roughly 0.5–1 GiB depending on caches/platform) and consume several GiB of disk for images/build layers. Obtain permission before downloading on a metered connection. A no-cache build means rebuilding instructions, not redownloading already cached base images.

## Local configuration

Copy the root template; there is no need to copy anyone's `laravel-app/.env`.

Linux/macOS/fish:

```fish
cp .env.example .env
```

Windows PowerShell:

```powershell
Copy-Item .env.example .env
```

Edit the new root `.env` locally. Set an administrator email/password, `RABBITMQ_PASSWORD` and `REVERB_APP_SECRET`. Use independent random values; a password manager can generate them. Where OpenSSL is installed, `openssl rand -hex 32` generates a suitable broker/Reverb secret. `ADMIN_EMAIL=admin@example.test` is a local shop identity and does not need an external mailbox. Keep it different from the customer email used below. Startup creates the administrator from these values.

On Linux/macOS restrict the file with `chmod 600 .env`. Never commit or include `.env` in a project archive. The broker and WebSocket server are local containers; no external RabbitMQ/Pusher account is needed.

## Use the same project Gmail sender

The mailbox owner supplies the **project Gmail address** and a Google **app password** privately. Prefer a separate app password labelled for each teammate/machine (for example `NovaCart-Ali`) so the owner can revoke one installation independently. The Google account's normal sign-in password and two-step verification codes are not needed by the application. The account owner creates app passwords under the already-enabled two-step verification; teammates do not create a new sender account. [Google app-password setup and revocation](https://support.google.com/accounts/answer/185833).

Set these local values:

```dotenv
MAIL_SCHEME=smtps
MAIL_HOST=smtp.gmail.com
MAIL_PORT=465
MAIL_USERNAME=shared-project-account@gmail.com
MAIL_PASSWORD="app-password-provided-privately"
MAIL_FROM_ADDRESS=shared-project-account@gmail.com
MAIL_RECIPIENT_ALLOWLIST=your-controlled-recipient@gmail.com
```

Two valid recipient choices:

- **Same sender, your own inbox:** put your own controlled email in `MAIL_RECIPIENT_ALLOWLIST` and register the shop customer using that address. Order confirmations come from the shared project Gmail sender, and you can verify receipt yourself without signing into its mailbox.
- **Same sender and shared inbox:** put the project Gmail address in `MAIL_RECIPIENT_ALLOWLIST` and register the shop customer using it. Each teammate has a separate local database, so using the same customer address on different machines is fine. The mailbox owner can confirm receipt. SMTP access does not itself sign your browser into Gmail.

An app password goes into `MAIL_PASSWORD` without display-grouping spaces. The allowed recipient is the customer's server-side registered email; the checkout request cannot override it. Multiple controlled recipients may be comma-separated. An unlisted recipient is a delivery failure that retries and eventually reaches the DLQ.

Each machine owns its own database, stock, Redis/cache if enabled and RabbitMQ queues. Sharing the email sender does not share shop accounts/orders. Local order numbers may repeat across machines; use the timestamp and message/event ID to distinguish confirmations. Changing the Google account's normal password revokes its app passwords; changing one local secret does not change another installation.

## Build and start

Use the same project name and file arguments for every command below:

```fish
docker compose -p novacart-team -f docker-compose.yml -f docker-compose.realtime.yml build --no-cache app web inventory
docker compose -p novacart-team -f docker-compose.yml -f docker-compose.realtime.yml up -d --no-build
docker compose -p novacart-team -f docker-compose.yml -f docker-compose.realtime.yml ps
```

These command lines also work in PowerShell. On Podman systems, use the installed Compose provider with the same project/files; this repository's local build/run checks use Podman. The PHP workers reuse the image built by `app`; do not start them before building it on a new machine. `pull_policy: never` prevents accidentally pulling the locally built core from a registry.

The app initializes its own database, default catalog, administrator and persistent runtime secrets. Broker provisioning finishes before consumers start. Core and Inventory databases, shared storage and RabbitMQ data use this Compose project's named volumes. No host source or `vendor/` bind mount is required in this normal deployment.

Open `http://localhost:8080`. If port 8080 is occupied, set `APP_PORT=8082` and `APP_URL=http://localhost:8082` in `.env` before starting. The WebSocket public port follows `APP_PORT`. The browser hostname should match `REVERB_PUBLIC_HOST` and `REVERB_ALLOWED_ORIGINS`; use localhost for the first test. RabbitMQ management is at `http://localhost:15672`, username `novacart`, with your local `RABBITMQ_PASSWORD`. Change `RABBITMQ_MANAGEMENT_PORT` if occupied.

## Demonstrate the same behavior

1. Open the shop in two separate browser profiles or a regular/private-window pair. Sign into one as the configured administrator and open **Manage shop → Orders**. Register the other as a customer using the allowlisted recipient.
2. Stop only the email consumer:

   ```fish
   docker compose -p novacart-team -f docker-compose.yml -f docker-compose.realtime.yml stop mail-worker
   ```

3. Add an in-stock product to the customer's cart and place the order. Checkout succeeds immediately. The already-open administrator list must show it without refreshing. Confirm pending mail in RabbitMQ management.
4. Restart the consumer:

   ```fish
   docker compose -p novacart-team -f docker-compose.yml -f docker-compose.realtime.yml start mail-worker
   ```

5. Verify the matching confirmation in the controlled inbox/spam folder. From the administrator screen, complete or cancel the order; the customer's open **Your orders** view must update without reload. Browser developer tools should show a genuine WebSocket and pushed events, not periodic status polling.
6. Inspect queue state without consuming messages:

   ```fish
   docker compose -p novacart-team -f docker-compose.yml -f docker-compose.realtime.yml exec app php artisan messaging:dlq mail
   ```

For retry/DLQ demonstrations, use disposable fixture data and the recovery instructions in the operator guide. Avoid repeatedly testing an incorrect Google password; a controlled blocked recipient or local SMTP fixture isolates the failure without risking the shared sender. Keep real test sends few and easy to identify.

## Stop and recover

```fish
docker compose -p novacart-team -f docker-compose.yml -f docker-compose.realtime.yml down
```

This preserves volumes for restart. `down --volumes` permanently removes that project's databases, storage/runtime keys and pending broker work; use it only when you deliberately discard this test installation. Do not copy an existing deployment's volumes or `.docker-secrets` to a teammate. Each machine should generate its own runtime keys.

After editing mail values, recreate the worker so the process reads the updated environment:

```fish
docker compose -p novacart-team -f docker-compose.yml -f docker-compose.realtime.yml up -d --no-build --force-recreate mail-worker
```

Do not paste unredacted `compose config`, environment output or SMTP authentication traces into chat/issues. Record the order/event ID and redacted outcome instead.
