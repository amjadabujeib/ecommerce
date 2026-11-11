# Distributed Real-Time E-Commerce Platform

Coursework project for **Internet Applications (2026)** at Damascus University.

> **Verification status (2026-11-11):** Requirements 1–2 technical acceptance passes, including the dark customer/admin GUI, source image builds, 89 Laravel tests (406 assertions), 15 real inventory tests, race/outage/compensation/recovery demonstrations and persistent-volume restart checks. Team participation is set aside by request. See [the final verification report](docs/verification/final-requirements-1-2.md) and [SPEC.md](SPEC.md). [Requirements 1–2](docs/requirements/requirements-1-2.md) and [requirement 3](docs/requirements/requirement-3.md) are converted from the presentations. Requirement 3 is implemented and locally verified on `feat/requirement-3-performance`; see the [performance and consistency evidence](docs/performance/README.md). The optional performance Compose overlay adds Redis and a PostgreSQL standby.

> **Subsequent deep review:** five open correctness/failure-handling findings were reproduced after the earlier passing checks. See the [review evidence](docs/verification/2026-11-11-deep-review.md); full technical acceptance is pending those fixes.

This project targets a distributed, polyglot e-commerce platform for **Requirement 1 (API Design & Security)** and **Requirement 2 (Inter-Service Communication & Inventory Safety)**.

---

## 1. System Architecture

```
                       HTTP / REST (/api/v1)
Clients (Web / Mobile / QA) ───────────────► Nginx Reverse Proxy (:8080)
                                                    │
                                                    ▼
                                            Laravel API Core (PHP 8.5)
                                            ├── Users Module (JWT, RBAC)
                                            ├── Products Module
                                            ├── Orders Module (IDOR Policies)
                                            └── Notifications Module
                                             │               │
                            SQL (PostgreSQL) │               │ gRPC / HTTP/2 (:50051)
                                             ▼               ▼
                                       App Database   Inventory Microservice (Node.js)
                                       (PostgreSQL)          │
                                                             │ SQL (PostgreSQL)
                                                             ▼
                                                      Inventory Database
                                                         (PostgreSQL)
```

The Laravel core is a modular monolith with module-owned models and queries, public catalog/user interfaces, and immutable order events. Inventory is the separate gRPC application required by requirement 2. See [module ownership and dependency rules](SPEC.md#module-ownership-and-public-interfaces).

### Key Technical Pillars

1. **Modular Core API Backend (Laravel 13, PHP 8.5)**:
   - Partitioned into 4 domain modules under `laravel-app/app/Modules/`: `Users`, `Products`, `Orders`, and `Notifications`.
   - Versioned REST APIs (`/api/v1/`) with unified JSON error envelopes: `{ "status": ..., "code": "...", "message": "...", "errors": {...} }`.
   - Stateless JWT authentication via `php-open-source-saver/jwt-auth` with refresh tokens and revocation blacklisting.
   - Role-Based Access Control (`customer` vs `admin`) and login rate limiting (6 requests/minute per account/IP).
   - Strict IDOR mitigation enforced via `OrderPolicy` (`403 Forbidden` on foreign order access).
   - Injected baseline security headers (`Content-Security-Policy`, `X-Frame-Options: DENY`, `X-Content-Type-Options: nosniff`, `Referrer-Policy`).

2. **Dedicated Inventory Microservice (Node.js, gRPC)**:
   - Standalone polyglot microservice running on port `50051`, backed by its own isolated PostgreSQL database.
   - Shared Protocol Buffers contract (`proto/inventory/v1/inventory.proto`) defining `GetStock`, `Reserve`, `Release`, and `SetStock`.
   - Concurrency safety: atomic row-level transactional locking (`SELECT ... FOR UPDATE` ordered by `product_id`) and database constraints (`CHECK (quantity >= 0)`), preventing deadlocks and race conditions.
   - Reservation and compensation checkout: orders execute an atomic all-or-nothing stock reservation prior to order persistence, executing a compensating release rollback if database persistence fails.
   - Fault tolerance: strict 2000ms gRPC client deadlines returning structured `503 Service Unavailable` with `Retry-After: 5` headers when the inventory service is stopped or degraded.

---

## 2. Project Layout

```
distributed-ecommerce/
├── laravel-app/          # Core REST API (PHP 8.5, Laravel, PostgreSQL)
│   ├── app/Modules/      # Users, Products, Orders, Notifications, Inventory
│   ├── database/         # Migrations, seeders, factories
│   ├── docker/           # PHP-FPM and Nginx configuration files
│   └── tests/            # Feature and security test suites
├── nodejs-inventory/     # Inventory microservice (Node.js, @grpc/grpc-js, pg)
│   ├── src/              # gRPC server, database pool, transactional logic
│   └── test/             # Unit and concurrency integration tests
├── proto/                # Shared Protocol Buffers contract
│   └── inventory/v1/     # inventory.proto
├── docs/                 # Postman collections & concurrency verification scripts
│   ├── api/              # NovaCart API & outage collections
│   └── demo/             # race-condition.mjs test script
├── docker-compose.yml    # Complete 6-container orchestration
└── SPEC.md               # Detailed system architectural specification
```

---

## 3. Team Member Division

| Member | Assigned Domain | Feature Branch | Core Responsibilities |
| :--- | :--- | :--- | :--- |
| **Member A** | Infrastructure, Docker & Contracts | `feature/docker-setup` | Multi-container Docker environment, base Laravel setup, and shared gRPC contract |
| **Member B** | Users, Authentication & Security | `feature/auth-and-roles` | User registration, JWT lifecycle, RBAC, login rate limiting, and security headers |
| **Member C** | Products, Catalog & Categories | `feature/product-catalog` | Product & category catalog endpoints, image uploads, and concurrency race demo |
| **Member D** | Orders, IDOR & Checkout | `feature/order-management` | Cart persistence, orders domain, IDOR policies, and reservation/compensation checkout orchestration |
| **Member E** | Inventory Microservice & gRPC | `feature/inventory-service` | Node.js gRPC inventory microservice, row-level locking, and Laravel gateway |

---

## 4. Execution & Quick Start

### Starting the Stack
```bash
docker compose up -d --build
```
Open the customer/admin GUI at **http://localhost:8080/**. The REST API is at `http://localhost:8080/api/v1`; `GET /up` is the application health endpoint. Set `ADMIN_EMAIL` and a nonempty `ADMIN_PASSWORD` in your environment before first startup to seed the administrator. Customers register in the GUI.

The GUI has dedicated product pages, named category filters, cart quantities/counts and separate administration sections. [GUI verification](docs/verification/gui-usability.md) covers Back/Forward, deep links and the actual shopping flow.

The GUI uses a dark palette. After a web image update, hard-refresh the browser.
If Podman keeps an older web container, apply the rebuilt image with
`docker compose up -d --no-deps --no-build --force-recreate web`.

The `scheduler` container retries abandoned checkout reservations every minute, after a five-minute grace period. You can also run `docker compose exec app php artisan orders:recover-reservations` manually. Keep it running for automatic recovery.

The PHP gRPC extension is already compiled and bundled in the repository as a
**6.35 MiB compressed file**. Teammates need no separate extension download or
manual installation: the Dockerfile checks and enables it. The PHP base is pinned
to the compatible Debian/amd64 image. App and scheduler share one core image,
so Compose builds it once. See the [artifact notes](laravel-app/docker/php/extensions/README.md)
for compatibility, provenance and updates. A first setup still needs ordinary
base images and locked application dependencies when those are not cached.

### Running Automated Test Suites
```bash
# Run Laravel feature and security tests
cd laravel-app && php artisan test

# Run inventory microservice tests
cd nodejs-inventory && npm test
```

### Running the Concurrency Race-Condition Proof
Dispatches simultaneous requests against an item with 1 remaining stock unit:
```bash
node docs/demo/race-condition.mjs
```
*Expected Outcome: Exactly 1 order succeeds (`201 Created`), remaining requests are rejected (`409 Conflict`), with zero negative inventory.*

### Running the Outage / Graceful Degradation Proof
```bash
# Stop the inventory service container
docker compose stop inventory

# Verify API responses return HTTP 503 Service Unavailable gracefully
docker compose start inventory
```
