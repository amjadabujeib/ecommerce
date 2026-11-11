# Course presentation: Requirements 1 and 2

## Source and conversion

- Source: `/home/amjad/Downloads/Telegram Desktop/مشروع_تطبيقات_الإنترنت_–_المتطلبان_الأول_والثاني_1.pptx`
- Course: Internet Applications, Damascus University, Faculty of Informatics Engineering, academic term 2026.
- Converted: 2026-11-10. All 15 slides were read visually: the PPTX contains slide images with no extractable text.
- This is an English translation of the substantive slide content, preserving slide order, lists, diagram relationships, and evaluation instructions. Repeated footers, decorative graphics, and the university logo are omitted. It is not a verbatim transcription of the Arabic text. Implementation choices belong in [SPEC.md](../../SPEC.md).

## Slide 1 — Distributed Real-Time E-Commerce Platform

Requirements 1 and 2: API Design & Security; Inter-Service Communication.

## Slide 2 — Project idea

Build a small shop that works like real systems. Focus on security, performance, scaling, and monitoring rather than the number of features or attractive pages.

Required basic functions:

- User registration.
- Add and display products with their images.
- Create orders.
- Manage inventory.
- Track order status.

Additional functions are allowed if they serve the project requirements. Frontend design is not evaluated.

## Slide 3 — Roadmap

There are six cumulative requirements; each builds on the previous one:

| Number | Requirement | Timing in this presentation |
| --- | --- | --- |
| 1 | API design and security | Today |
| 2 | Inter-service communication | Today |
| 3 | Performance and caching | Later |
| 4 | Asynchronous processing and real-time behavior | Later |
| 5 | Scaling and load distribution | Later |
| 6 | Monitoring and fault tracing | Later |

Start with requirements 1 and 2. Subsequent requirements are provided during interviews.

## Slide 4 — Requirement 1: API Design & Security

Goal: an organized, documented API through which each user can access only what they are permitted to access.

## Slide 5 — Requirement 1: Architecture

One project organized into four modules:

| Module | Responsibility |
| --- | --- |
| Users | Registration, login, roles |
| Products | Add and display products with images |
| Orders | Create orders and track their status |
| Notifications | Everything sent to the user |

The team freely chooses the language and framework. The whole project runs using Docker in separate containers.

## Slide 6 — Requirement 1: APIs

Clear, documented REST interfaces:

- Products: add a product with its image, list products, filter by category, paginate results.
- Orders: create an order, display an order, change its status.
- All interfaces: put a version in the path, use correct status codes, use one error format, and provide documentation that is easy to try.

Documentation can use OpenAPI/Swagger, a Postman Collection, or an equivalent tool.

## Slide 7 — Requirement 1: Security

- A customer sees only their own orders.
- Only an administrator changes order status.
- JWT authentication.
- Limit login attempts.
- Basic security headers.

## Slide 8 — Requirement 1: Required proof

Demonstrate a user trying to access another user's order or perform an operation outside their permissions.

**Expected result: the request is rejected.**

## Slide 9 — Requirement 2: Inter-Service Communication

Goal: separate inventory management into an independent service that the application contacts directly through gRPC.

## Slide 10 — Requirement 2: Architecture

Two services using different languages or frameworks agree on one contract:

```text
Core application -- gRPC / shared .proto --> Warehouse service
       |                                           |
Application database                       Warehouse database
```

The core application uses the team's selected framework. The warehouse is a polyglot service written in a different language or framework, owns its own database, and is contacted by the application only through the contract.

## Slide 11 — Requirement 2: Contract and flows

The contract provides three operations:

1. Query quantity: how much of a product remains?
2. Reserve quantity: reserve the quantities of an order's products.
3. Return quantity: return what was reserved if the order fails.

Product detail flow:

1. The application asks the warehouse for the available quantity.
2. It displays the quantity with the product details.

Order creation flow:

1. Make one request to reserve the quantities of all products in the order.
2. If approved, continue creating the order.
3. If rejected, do not create the order.

## Slide 12 — Requirement 2: Failure cases

| Failure | Required behavior |
| --- | --- |
| Insufficient quantity | Reject the order with a clear message. |
| Warehouse stopped or slow | Set a deadline for every call; the user receives a clear response without waiting indefinitely. |
| Saving fails after reservation | Return the reserved quantities to the warehouse. |

## Slide 13 — Requirement 2: Required proof

Several orders target the last unit at the same time.

**Expected result: exactly one order succeeds.** This is the race condition problem.

Also demonstrate what the user sees when the warehouse service stops.

## Slide 14 — Work process and assessment

- Use one Git repository with a protected main branch.
- Every change goes through a pull request reviewed by a teammate.
- Each member must have at least one pull request for each requirement.
- Any member may be asked about any part of the project.
- At each interview, the team is evaluated on the requirements it has received and receives the next requirements.

## Slide 15 — Closing

Good luck; questions. Internet Applications project, Damascus University, 2026.
