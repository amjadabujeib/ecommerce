# Repository agent instructions

## Scope and source of truth

These instructions apply throughout this repository.

- Read [SPEC.md](SPEC.md) before changing behavior, reviewing compliance, or declaring a milestone complete. It separates course obligations, project decisions, acceptance checks, and observed gaps.
- When interpreting instructor requirements, read the slide-numbered conversions: [requirements 1–2](docs/requirements/requirements-1-2.md) and [requirement 3](docs/requirements/requirement-3.md). Preserve their meaning; put implementation decisions in SPEC.md.
- Establish and verify requirements 1 and 2 first. Implement requirement 3 only when the user's task advances to that milestone. Requirements 4–6 have roadmap titles only.
- Inspect the current checkout before trusting documentation claims. SPEC.md and [the verification report](docs/verification/requirements-1-2.md) record the merged implementation, passing checks, and pending checks; update that evidence when the state changes.

## Architecture and compatibility

- Keep Users, Products, Orders, and Notifications as the core domain modules. The Laravel Inventory module is a service adapter.
- Cross-module references use the owning module's `PublicApi` only; the Inventory adapter exposes its existing `Contracts`, `Data`, and `Exceptions`. Keep the dependency direction documented in SPEC.md. Models, ORM relationships, query builders, and HTTP resources stay inside their owner; exchange IDs/snapshots through public interfaces.
- Product deletion guards run synchronously; order events carry immutable committed data. Batch catalog/profile enrichment rather than resolving foreign data per order line.
- Inventory owns its separate database. Core stock access goes through gRPC and [the shared proto](proto/inventory/v1/inventory.proto); update both service implementations, generated clients, and contract tests together when changing it.
- Preserve atomic multi-item reservations, reservation-ID idempotency, compensating release, and explicit RPC deadlines. A timeout may follow a remote commit; handle that ambiguity rather than reserving again with a new ID.
- Enforce ownership/roles before side effects. Derive customer identity and order prices on the server.
- Preserve the API compatibility baseline and error envelope in SPEC.md. When changing an interface, update the [Postman collections](docs/api/) and [race demo](docs/demo/race-condition.mjs) with the implementation.
- Keep infrastructure/runtime versions in manifests, lockfiles, and Dockerfiles; inspect those rather than copying old README version claims.

## Verification and reporting

- For module changes, run `php tests/Architecture/check-modules.php` from `laravel-app/`, then the affected behavior checks.
- For core changes, use applicable Laravel HTTP tests. `php artisan test` from `laravel-app/` is the intended runner once development dependencies are available. Inspect tests and database configuration before running.
- For inventory changes, run the Node service's gRPC/PostgreSQL tests with a disposable `TEST_DATABASE_URL`. The test helper creates and removes isolated schemas.
- For distributed correctness, use real gRPC/PostgreSQL integration and the SPEC.md acceptance gate. Inventory fakes and SQLite do not prove locking, network behavior, or last-unit safety.
- Run race, outage, and persistence-failure demonstrations only against disposable test data in an authorized environment; they change stock/orders or service availability. Verify all losing race requests, final stock, and persisted order count, not just script exit status.
- Report what was verified, what failed, and what remains untested. Keep SPEC.md's implementation evidence and affected documentation consistent with material changes. Documentation edits need link/content checks, not application test scaffolding.

## Collaboration and local environment

- Keep requirements 1–2 work on `test/requirements-1-2-acceptance` with focused progressive commits. Create a separate requirement 3 branch when that milestone begins; the temporary branch stack has been retired.
- Use feature branches and teammate-reviewed PRs for shared changes. Course participation and branch-protection requirements are in SPEC.md. Do not commit, push, or merge unless requested.
- Write user-facing explanations in English. For interactive host instructions, use fish-compatible syntax and Arch/CachyOS package commands; container commands follow the container's own OS.
- Keep network use lean. Obtain explicit authorization before installing dependencies/packages, downloading large files or container images, or starting/stopping/enabling services. State expected size before proposing large downloads.
- Preserve secrets outside tracked files. Use disposable fixtures for demonstrations and retain recovery/cleanup steps when a check mutates data.
