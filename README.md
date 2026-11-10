# Distributed Real-Time E-Commerce Platform

Coursework project for **Internet Applications (2026)** at Damascus University.

> **Implementation status (2026-11-10):** Core and inventory code is merged. Requirements 1 and 2 still need the acceptance checks and source fixes described in [SPEC.md](SPEC.md). The presentations are converted in [requirements 1–2](docs/requirements/requirements-1-2.md) and [requirement 3](docs/requirements/requirement-3.md). Requirement 3 remains deferred.

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
   - Two-phase checkout orchestration: orders execute an atomic all-or-nothing stock reservation prior to order persistence, executing a compensating release rollback if database persistence fails.
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
├── docker-compose.yml    # Complete 5-container orchestration
└── SPEC.md               # Detailed system architectural specification
```

---

## 3. Team Member Division

| Member | Assigned Domain | Feature Branch | Core Responsibilities |
| :--- | :--- | :--- | :--- |
| **Member A** | Infrastructure, Docker & Contracts | `feature/docker-setup` | Multi-container Docker environment, base Laravel setup, and shared gRPC contract |
| **Member B** | Users, Authentication & Security | `feature/auth-and-roles` | User registration, JWT lifecycle, RBAC, login rate limiting, and security headers |
| **Member C** | Products, Catalog & Categories | `feature/product-catalog` | Product & category catalog endpoints, image uploads, and concurrency race demo |
| **Member D** | Orders, IDOR & Two-Phase Checkout | `feature/order-management` | Cart persistence, orders domain, IDOR policies, and two-phase checkout orchestration |
| **Member E** | Inventory Microservice & gRPC | `feature/inventory-service` | Node.js gRPC inventory microservice, row-level locking, and Laravel gateway |

---

## 4. Execution & Quick Start

### Starting the Stack
```bash
docker compose up -d --build
```
The REST API is exposed at `http://localhost:8080/api/v1` (`GET /up` serves as the health check).

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
