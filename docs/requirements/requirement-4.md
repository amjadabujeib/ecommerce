# Course presentation: Requirement 4

## Source and conversion

- Source: `/home/amjad/Downloads/Telegram Desktop/DOC-20261109-WA0031(1).pptx`.
- SHA-256: `9be4248566de2ec85960b4ac94c3c84514eb848ee58e938f6609b247e2d0e7f1`.
- Course: Internet Applications project, Damascus University, Faculty of Informatics Engineering, academic term 2026.
- Converted: 2026-11-17. All 12 slides were inspected visually. Each slide contains an image rather than extractable text; slide-to-image relationships were checked in the PPTX archive.
- This is an English translation of the substantive content in slide order, including diagram relationships and demonstration instructions. Decorative graphics and repeated footers are omitted. No technical requirement has been inferred from a decorative element.
- The separate [interview checklist image](requirement-4-interview.md) restates the required behaviors. Repository choices, acceptance tests and implementation status belong in [SPEC.md](../../SPEC.md#7-requirement-4--asynchronous-queues-and-real-time).

## Slide 1 — Asynchronous Queues & Real-Time

Requirement 4: asynchronous processing and real-time updates, for the distributed real-time e-commerce platform.

## Slide 2 — Where we are

The system is fast; now make it avoid waiting and delays.

| Number | Requirement | Presentation status |
| --- | --- | --- |
| 1 | API design and security | Completed |
| 2 | Inter-service communication | Completed |
| 3 | Performance and caching | Completed |
| 4 | Asynchronous processing and real-time updates | Today |
| 5 | Scaling and load distribution | Later |
| 6 | Monitoring and fault tracing | Later |

These labels describe the course sequence, not independent verification of a repository.

## Slide 3 — Goal

What the user does not need to wait for happens in the background. What matters to the user reaches their screen immediately.

## Slide 4 — Two problems this requirement solves

**Unnecessary waiting:** the customer waits for a confirmation email to be sent before knowing their order succeeded. If the email service fails, the entire request may fail. Solution: queues.

**The screen does not know what changed:** the customer does not see that an administrator approved their order, and the administrator does not see a new order, unless they refresh the page. Solution: real-time communication.

## Slide 5 — Queue concept: the cashier does not cook

| Restaurant analogy | Project equivalent | Behavior |
| --- | --- | --- |
| Cashier | Application | Takes the order and responds immediately |
| Hanging order slip | Message in a queue | Waits until someone is available to process it |
| Kitchen | Worker | Takes the message and executes it in the background |

The customer does not wait at the cashier until their food is cooked; similarly, they should not wait until email is sent.

## Slide 6 — What happens when an order is created?

```text
1. Customer places an order
   -> 2. Application saves the order
   -> 3. Message enters RabbitMQ
   -> 4. Worker receives it
   -> 5. Real email goes to the customer
```

Immediately after step 3, the application responds to the customer: their order has been created. Steps 4 and 5 happen in the background; the customer does not wait for them.

## Slide 7 — Do not lose messages when the background process fails

- **Acknowledge after success:** the worker acknowledges receipt only after it has actually sent the email.
- **Retry:** if sending fails, retry several times before acknowledging the message.
- **Dead-letter queue:** a message that keeps failing goes to a separate DLQ rather than blocking other messages.
- Hint: investigate RabbitMQ's Dead Letter Exchange.
- Email must be real, sent through an external email service. Access credentials belong in configuration, not in application code.

The slide does not prescribe a retry count, retry delay, email vendor or queue name.

## Slide 8 — Real-time communication: from asking repeatedly to server push

| Ordinary request | WebSocket |
| --- | --- |
| Browser asks, server answers, connection ends | Connection remains open |
| Browser learns of changes only by asking again | Server sends an update when the change happens |

Do not write a WebSocket server from scratch. Use an existing server, such as Reverb, Soketi, or another Pusher-compatible server. These are examples, not a requirement to use all of them.

## Slide 9 — Two channels with different subscription permissions

| Channel purpose | Who may subscribe? | What arrives? |
| --- | --- | --- |
| Order tracking page | Only the order owner | Order status changes, for example administrator approval |
| Administrator dashboard | Administrators only | A new order at the moment it is created |

Verify the user's identity and permission before allowing a subscription to either channel.

## Slide 10 — Two browsers, changes appear immediately in the other

```text
Customer browser creates an order
   -> New order appears immediately in the administrator's browser
   -> Administrator approves the order
   -> Status changes immediately in the customer's browser
```

No page refresh at any step.

## Slide 11 — Required proof

- Stop the worker, then start it again: no email is lost.
- A new order appears immediately on the administrator dashboard.
- Administrator approval appears immediately on the customer's page.

## Slide 12 — Closing

Good luck; questions. Internet Applications project, Damascus University, 2026.
