# Distributed Real-Time E-Commerce Platform — Requirements 1 & 2 Specification

---

## Problem Statement

Modern online commerce systems require high availability, dependable data consistency, and resilient security under variable network conditions and high concurrency. Traditional monolithic applications frequently face severe vulnerabilities and design pitfalls:

1. **Access Control & Authorization Failures**: Systems vulnerable to Insecure Direct Object Reference (IDOR) allow unauthorized customers to view, modify, or cancel orders belonging to other users simply by manipulating identifiers in route parameters. Furthermore, lack of strict role boundaries enables non-privileged users to alter critical lifecycle states, such as artificially transitioning order statuses.
2. **Double-Selling and Inventory Race Conditions**: When multiple customers attempt to purchase the last remaining unit of an item at the exact same millisecond, naive inventory updates permit race conditions, producing overselling, inventory inaccuracies, and inconsistent state.
3. **Cascading Microservice Outages**: As organizations transition toward distributed services, network calls between services can fail or experience extreme latency. Without deterministic timeouts, atomic reservation semantics, and graceful degradation, inventory subsystem outages cascade across the platform, causing thread pool exhaustion and unhandled application crashes.
4. **Lack of Standardized Contracts**: Inconsistent error response formats and untyped or undocumented inter-service protocols create fragility between backend layers and client consumers.

From the user and evaluator perspective, this project must deliver a secure, polyglot, containerized e-commerce backend where security guarantees (IDOR mitigation, RBAC, brute-force protection) and distributed fault-tolerance (atomic gRPC inventory locking, race-condition immunity, graceful degradation) are provably verified.

---

## Solution

The solution is a containerized, polyglot microservice e-commerce platform addressing **Requirement 1 (API Design & Security)** and **Requirement 2 (Inter-Service Communication)**:

1. **Modular Core API Backend (Laravel)**:
   - Structured into four strictly separated domain modules: **Users**, **Products**, **Orders**, and **Notifications**.
   - Exposes versioned RESTful APIs (`/api/v1/`) with standardized semantic HTTP status codes, uniform JSON error formats, and interactive Swagger/OpenAPI documentation.
   - Secures communication via stateless JWT authentication with Role-Based Access Control (RBAC) supporting `customer` and `admin` roles.
   - Enforces strict authorization policies preventing IDOR vulnerabilities (customers can only access their own orders; order state changes are strictly restricted to administrators).
   - Applies security headers and rate-limiting throttling middleware on sensitive authentication endpoints.

2. **Dedicated Inventory Microservice (Node.js, gRPC)**:
   - A standalone polyglot microservice written in Node.js with its own fully isolated PostgreSQL database container.
   - Communicates exclusively with the core API via gRPC over HTTP/2 using a shared Protocol Buffers (`proto/inventory/v1/inventory.proto`) contract.
   - Exposes atomic operations: querying available stock (`GetStock`), atomic multi-item stock reservation (`Reserve`), compensating stock release (`Release`), and administrative stock updates (`SetStock`).
   - Implements transactional row locking (`SELECT ... FOR UPDATE` ordered by `product_id`) and database constraints (`CHECK (quantity >= 0)`) to guarantee ACID safety against race conditions and overselling during high-concurrency checkouts.

3. **Two-Phase Order Coordination with Compensating Transactions**:
   - Order creation executes an atomic reservation RPC against the Warehouse microservice before persisting the order in the core database.
   - If stock is insufficient, the checkout request is immediately rejected.
   - If the core application fails to persist the order record following a successful reservation, an automated compensating rollback RPC (`ReleaseStock`) is triggered to release the reserved stock immediately.

4. **Resilience, Timeouts, and Graceful Degradation**:
   - All inter-service gRPC invocations are bound by strict client deadlines/timeouts (e.g., 2000ms).
   - If the Warehouse microservice is offline or times out, the core API catches the failure and returns a structured, user-friendly 503 Service Unavailable response without unhandled exceptions or downtime.

---

## User Stories

### User Management & Authentication

1. As a new customer, I want to register an account with my name, email, and password, so that I can log in and place orders on the store.
2. As a registered customer, I want to authenticate using my email and password, so that I receive a secure JWT token for subsequent API requests.
3. As a store administrator, I want to authenticate with administrator credentials, so that I receive a JWT token bearing administrator privileges.
4. As an API client, I want invalid login attempts to return a 401 Unauthorized error with a standardized JSON error message, so that I understand why authentication failed.
5. As a system operator, I want login requests to be rate-limited (e.g., maximum 5 attempts per minute per IP), so that the application is protected against automated brute-force attacks.
6. As an authenticated user, I want my JWT token to carry expiration claims, so that hijacked or obsolete tokens expire automatically.
7. As an authenticated user, I want to send my JWT token in the `Authorization: Bearer <token>` header, so that the API can verify my identity on protected endpoints.
8. As a customer, I want to be prevented from accessing administrative endpoints, so that privileged administrative operations remain secure.

### Product Catalog & Media Management

9. As an administrator, I want to create a new product specifying its title, description, price, category, and image file, so that it becomes available in the catalog.
10. As an administrator, I want product image uploads to be validated for file type and size, so that malicious or excessively large files are rejected.
11. As a customer, I want to view a paginated list of available products, so that I can browse items without loading excessive data in a single request.
12. As a customer, I want to filter products by category, so that I can easily discover items relevant to my interests.
13. As a customer, I want to retrieve details of a specific product by ID, so that I can review its specifications, pricing, and current stock level.
14. As a customer viewing a product page, I want to see the real-time available stock count retrieved from the warehouse service, so that I know whether the item is available before attempting to purchase.
15. As an API client, I want requesting a non-existent product ID to return a 404 Not Found status with the unified error format, so that missing resources are handled gracefully.

### Order Processing & Inventory Reservation

16. As a customer, I want to create an order by specifying one or more items and their desired quantities, so that I can purchase products.
17. As an order processing service, I want to validate order line items before contacting the warehouse, so that malformed requests (zero or negative quantities, non-existent products) are rejected immediately.
18. As a customer placing an order, I want the system to atomically reserve inventory for all items in my order via the warehouse service, so that all items are guaranteed before my order is confirmed.
19. As a customer, I want my order to be rejected with a 422 Unprocessable Entity error if any requested product has insufficient stock, so that I am notified which item caused the failure.
20. As a customer, I want my order to be saved with a `PENDING` status upon successful stock reservation, so that my order history reflects the purchase.
21. As a customer placing an order, I want the system to automatically release my reserved inventory if an internal database error occurs during order persistence, so that stock is not permanently locked by a failed checkout.
22. As a customer, I want to receive an order confirmation payload containing the order ID, status, item details, and total price upon successful checkout, so that I have a record of my purchase.

### Order Visibility, Access Control & Authorization (IDOR Protection)

23. As a customer, I want to view a list of all my previous orders, so that I can track my purchasing history.
24. As a customer, I want to view the details and line items of any order that belongs to me, so that I can verify order information.
25. As a customer, I want to be strictly blocked with a 403 Forbidden status when attempting to access an order belonging to another customer by modifying the ID parameter, so that my private purchase data is protected against IDOR attacks.
26. As a customer, I want to be blocked with a 403 Forbidden status when attempting to update or alter an order's status, so that customers cannot fraudulently modify fulfillment states.
27. As an administrator, I want to view any order in the platform, so that I can oversee store operations and customer inquiries.
28. As an administrator, I want to update an order's status (e.g., from `PENDING` to `PROCESSING`, `COMPLETED`, or `CANCELLED`), so that the order advances through its fulfillment lifecycle.

### Warehouse Service & High-Concurrency Inventory Safety

29. As a warehouse service, I want to store inventory quantities in a dedicated, isolated database, so that inventory data is completely separated from the core application database.
30. As a warehouse service, I want to expose a gRPC endpoint (`GetStock`) to query available quantity for a product, so that client services can query stock levels efficiently.
31. As a warehouse service, I want to expose an atomic gRPC endpoint (`ReserveStock`) that reserves multiple items in a single transaction, so that orders either succeed in reserving all required items or reserve none.
32. As a warehouse service, I want to expose a compensating gRPC endpoint (`ReleaseStock`) to restore reserved quantities, so that failed or cancelled orders do not leak inventory.
33. As an e-commerce platform under high traffic, I want concurrent purchase requests targeting the last remaining unit of an item to be serialized via transactional locking, so that exactly one customer succeeds and all subsequent requests are rejected without overselling or negative stock.
34. As a warehouse service, I want all multi-item database locks to be acquired in a consistent, deterministic order (e.g. sorted by Product ID), so that concurrent transactions do not cause database deadlocks.

### Fault Tolerance, Resiliency & Observability

35. As the core application, I want all gRPC calls to the warehouse service to execute with a strict timeout deadline (e.g., 2000ms), so that client requests do not hang indefinitely when the warehouse service is slow or unresponsive.
36. As a customer, I want to receive a clean, structured 503 Service Unavailable response with an understandable error message if the warehouse service is offline, so that I am not exposed to raw system traces or unhandled server crashes.
37. As an API developer, I want all responses to include standard HTTP security headers (e.g., `X-Content-Type-Options: nosniff`, `X-Frame-Options: DENY`, `Content-Security-Policy`), so that the API meets industry baseline security standards.
38. As an API developer or QA engineer, I want to inspect and test all REST endpoints interactively via Swagger UI / OpenAPI documentation, so that I can explore and verify API contracts without external tools.
39. As a course evaluator or developer, I want to run a concurrency verification script that dispatches simultaneous order requests against a single remaining stock unit, so that I can demonstrably verify race condition immunity.
40. As a course evaluator or developer, I want to simulate a warehouse outage by stopping the warehouse container, so that I can verify the core application handles service downtime gracefully.

---

## Implementation Decisions

### Architectural Decisions

1. **Modular Monolith for Core API (Laravel)**:
   - The primary backend application is organized into four distinct modules:
     - `Users`: Handles user registration, authentication, token issuance, and role definitions.
     - `Products`: Manages catalog definitions, category filtering, pagination, and media storage.
     - `Orders`: Manages cart-to-order transformation, status transitions, IDOR authorization policies, and distributed reservation orchestration.
     - `Notifications`: Provides user notification records and triggers.
   - Modules maintain distinct bounded contexts. Direct cross-module database joins are forbidden; modules interact exclusively via defined service layers and domain boundaries.

2. **Polyglot Service Isolation**:
   - The primary application is built with Laravel (PHP 8.5).
   - The inventory microservice is an independent polyglot service built with Node.js (`@grpc/grpc-js`, `pg`).
   - Each service manages its own dedicated database instance (`novacart` for Laravel, `inventory` for the microservice). The Laravel application has zero direct database connectivity to the inventory database.

3. **Inter-Service Communication via gRPC**:
   - All communication between the Core API and Inventory microservice is strictly executed via gRPC over HTTP/2.
   - A single shared Protocol Buffers definition (`proto/inventory/v1/inventory.proto`) acts as the single source of truth contract.
   - The Node.js service loads the `.proto` dynamically at runtime via `@grpc/proto-loader`.
   - The Laravel application communicates through an `InventoryGateway` abstraction backed by generated PHP protobuf classes and a `FakeInventory` test double.

4. **Containerization & Orchestration**:
   - The complete environment is orchestrated using Docker Compose.
   - Containers:
     - `app`: Laravel Core API service (PHP 8.5-FPM with compiled `grpc` PECL extension).
     - `web`: Nginx reverse proxy serving the REST API on port 8080.
     - `db`: PostgreSQL 17 database for Core API.
     - `inventory`: Node.js Inventory gRPC microservice on port 50051.
     - `inventory-db`: Dedicated PostgreSQL 17 database for the Inventory service.

### API Contracts & Schemas

1. **REST API Versioning & Routing**:
   - All REST API routes are prefixed with `/api/v1/`.
   - Core endpoints:
     - `POST /api/v1/auth/register` (Public)
     - `POST /api/v1/auth/login` (Public, Rate-Limited)
     - `GET /api/v1/products` (Public, Paginated, Filterable by `category`)
     - `GET /api/v1/products/{id}` (Public)
     - `POST /api/v1/products` (Admin Only, Multipart Form Data)
     - `POST /api/v1/orders` (Authenticated Customer)
     - `GET /api/v1/orders` (Authenticated User: Customer sees own, Admin sees all)
     - `GET /api/v1/orders/{id}` (Authenticated User: IDOR-protected)
     - `PATCH /api/v1/orders/{id}/status` (Admin Only)

2. **Unified Error Response Format**:
   - All non-2xx responses adhere to a consistent schema:
     - `success`: Boolean (`false`)
     - `error.code`: Machine-readable error code string (e.g., `VALIDATION_ERROR`, `UNAUTHORIZED`, `FORBIDDEN`, `RESOURCE_NOT_FOUND`, `INSUFFICIENT_STOCK`, `SERVICE_UNAVAILABLE`)
     - `error.message`: Human-readable error description
     - `error.details`: Array or dictionary of specific field validation errors (optional)
     - `error.timestamp`: ISO-8601 timestamp

3. **gRPC Protocol Buffer Contract (`proto/inventory/v1/inventory.proto`)**:
   - Defines package `inventory.v1`.
   - `InventoryService` RPCs:
     - `rpc GetStock (GetStockRequest) returns (GetStockResponse);`
       - Queries available stock levels for a batch of product IDs (`repeated int64 product_ids`).
     - `rpc Reserve (ReserveRequest) returns (ReserveResponse);`
       - Atomically reserves quantities for all line items under a caller-supplied `reservation_id` (UUID). Returns `RESERVED`, `REJECTED` (with shortages), or `RELEASED`. Idempotent.
     - `rpc Release (ReleaseRequest) returns (ReleaseResponse);`
       - Restores reserved stock units using the `reservation_id` if order persistence fails or an order is cancelled. Idempotent.
     - `rpc SetStock (SetStockRequest) returns (SetStockResponse);`
       - Allows administrative updates of absolute product stock counts.

### Concurrency and Transactional Decisions

1. **Inventory Concurrency Safety**:
   - The Node.js inventory microservice executes stock reservations within an explicit SQL transaction with row-level locking (`SELECT quantity FROM stock WHERE product_id = $1 FOR UPDATE`).
   - To avoid database deadlocks during multi-item orders, items in incoming reservation requests are sorted deterministically by Product ID prior to acquiring row locks.
   - The database enforces `CHECK (quantity >= 0)`. If any requested item has insufficient quantity, the transaction is rolled back completely with status `REJECTED` and shortages reported (atomic all-or-nothing).

2. **Compensating Rollback Flow (Saga Pattern)**:
   - Order creation follows a two-phase sequence:
     1. Client submits checkout request to `/api/v1/orders`.
     2. Core API calls `InventoryService.Reserve` via gRPC with a unique UUID `reservation_id`.
     3. If reservation fails (`REJECTED`), the Core API immediately terminates the request and returns `409 Conflict` (or `422 Unprocessable Entity`).
     4. If reservation succeeds (`RESERVED`), the Core API attempts to persist the order and line items in its local database inside a database transaction.
     5. If local persistence fails (e.g., database connection dropped or constraint violation), the Core API invokes `InventoryService.Release` via gRPC before returning `500 Internal Server Error`, ensuring no orphaned inventory reservations remain.

3. **Timeouts & Graceful Outage Handling**:
   - gRPC client calls from Laravel configure a gRPC context deadline of 2000ms (`INVENTORY_TIMEOUT_MS`).
   - In the event of a `DEADLINE_EXCEEDED` or `UNAVAILABLE` gRPC status, the Core API logs the incident and returns a `503 Service Unavailable` response with `Retry-After: 5` header, preventing hanging processes and cascading client timeouts.

### Security Decisions

1. **Authentication & Authorization**:
   - Token mechanism: JSON Web Token (JWT) with HMAC-SHA256 signature and 60-minute expiration.
   - Roles: `customer` and `admin`.
   - Policies & Gate Middleware:
     - `OrderPolicy@view`: Allows access if `user.role === 'admin'` OR `order.user_id === user.id`. Rejects with `403 Forbidden` otherwise.
     - `OrderPolicy@updateStatus`: Allows access only if `user.role === 'admin'`. Rejects with `403 Forbidden` otherwise.
2. **Rate Limiting**:
   - Applied to `/api/v1/auth/login`: 5 requests per minute per IP address, returning `429 Too Many Requests`.
3. **Security Headers Middleware**:
   - Injects standard security headers:
     - `X-Content-Type-Options: nosniff`
     - `X-Frame-Options: DENY`
     - `X-XSS-Protection: 1; mode=block`
     - `Strict-Transport-Security: max-age=31536000; includeSubDomains`
     - `Content-Security-Policy: default-src 'self'`

---

## Testing Decisions

### What Makes a Good Test

Tests must verify observable system behavior from external interfaces rather than inspecting internal implementation details. Tests should:
- Issue real HTTP or gRPC requests and assert against HTTP status codes, JSON payload envelopes, and headers.
- Verify security boundaries by demonstrating that unauthorized or forged requests fail with deterministic error codes (`401`, `403`, `429`).
- Verify data consistency across distributed boundaries by testing state changes after transactions and rollback compensation.
- Avoid mocking internal domain logic where real containerized service interactions are critical to validating architectural requirements.

### Testing Seams

The platform is tested at three distinct seams, prioritizing the highest possible integration seam:

1. **Primary Seam: External HTTP REST API (Highest Seam)**:
   - Evaluates the entire integrated system end-to-end through HTTP calls.
   - Tests cover:
     - User registration, token acquisition, and role validation.
     - Login rate-limiting threshold enforcement (asserting HTTP 429).
     - Product catalog retrieval, pagination metadata, category filters, and file upload validation.
     - Customer order placement and retrieval.
     - **IDOR Access Verification**: An authenticated customer attempts to access `/api/v1/orders/{other_customer_order_id}` and receives HTTP 403 Forbidden.
     - **Role Authorization Verification**: An authenticated customer attempts to invoke `PATCH /api/v1/orders/{id}/status` and receives HTTP 403 Forbidden.
     - **Graceful Fault Injection**: With the warehouse container stopped, checkout requests return HTTP 503 Service Unavailable with the unified error structure.

2. **Secondary Seam: gRPC Service Interface Boundary**:
   - Tests the Node.js Inventory microservice directly via gRPC client calls and integration test suite (`npm test`).
   - Tests cover:
     - Initial stock queries (`GetStock`).
     - Multi-item atomic reservations (`Reserve`) verifying all-or-nothing behavior and idempotency.
     - Compensating release calls (`Release`) verifying that inventory levels accurately increment back to original amounts.

3. **Concurrency & Race Condition Test Harness**:
   - A dedicated asynchronous test script (`docs/demo/race-condition.mjs`) that sets a test product's stock count to exactly 1 unit in the warehouse.
   - Dispatches simultaneous HTTP checkout requests at the exact same millisecond.
   - Asserts that **exactly one** request returns `201 Created`, while all other requests receive `409 Conflict`.
   - Asserts that the final stock count in the inventory database is exactly 0 and never drops into negative numbers.

### Prior Art

- Laravel Feature Tests using `Tests\TestCase` and PHPUnit HTTP assertions (`assertStatus`, `assertJsonStructure`, `assertForbidden`).
- Node.js test runner verifying transactional SQL isolation and row-level locking.
- Postman / Newman test collections runnable against containerized environments.

---

## Out of Scope

The following features and concerns are explicitly out of scope for Requirements 1 and 2:

1. **Frontend User Interface & Visual Styling**: The project is evaluated exclusively on backend architecture, API standards, security, and inter-service communication. No client-side UI frameworks or CSS styling are evaluated.
2. **Payment Gateway Integration**: Real or sandbox monetary payment providers (e.g., Stripe, PayPal, local bank APIs) are excluded. Order checkout advances directly to inventory reservation and confirmation without financial processing.
3. **Advanced Caching (Requirement 3)**: Redis caching layers and read-through caching for product catalogs will be introduced in subsequent milestones.
4. **Asynchronous Message Queues & Real-Time WebSockets (Requirement 4)**: Background queue workers (RabbitMQ/Kafka) and real-time push notifications are reserved for milestone 4.
5. **Horizontal Scaling & Load Balancing (Requirement 5)**: Multi-replica deployments and reverse proxy load balancing algorithms will be addressed in milestone 5.
6. **Distributed Tracing & APM Dashboards (Requirement 6)**: Centralized metric aggregation (Prometheus, Grafana) and OpenTelemetry distributed tracing will be addressed in milestone 6.
7. **Complex E-Commerce Domain Features**: Customer product reviews, promotional coupons, discount engines, multi-currency conversion, and shipment tracking integrations.

---

## Further Notes

### Team Organization & Task Alignment

In accordance with the team plan, responsibilities are distributed across five team members:
- **Member A (Infrastructure)**: Repository structure, protected `main` branch rules, Docker Compose configuration for all containers, unified error response middleware, and HTTP security headers.
- **Member B (Users & Security)**: User registration, JWT authentication, RBAC middleware, login rate-limiting, and Laravel gRPC client connection with timeout handling.
- **Member C (Products & Documentation)**: Product catalog endpoints, image storage handling, pagination/filtering, OpenAPI/Swagger interactive documentation, and high-concurrency race condition testing script.
- **Member D (Orders & Notifications)**: Order creation and viewing, IDOR prevention policies, administrative status transition controls, notification module structure, and end-to-end two-phase reservation orchestration.
- **Member E (Inventory Service)**: Standalone Node.js microservice architecture, Protocol Buffers contract implementation, atomic gRPC reservation and release logic, row-level locking for race-condition prevention, and Laravel gateway integration.

### Codebase & Development Standards

- **Git Workflow**: A single shared GitHub repository with a protected `main` branch. Direct commits to `main` are restricted. Every change must be delivered via a Pull Request (PR) reviewed and approved by at least one teammate.
- **Participation**: Every team member mustAuthor and merge at least one PR per requirement.
- **Collective Ownership**: During university evaluation interviews, any team member may be questioned regarding any portion of the architecture, necessitating complete shared understanding of all modules.
