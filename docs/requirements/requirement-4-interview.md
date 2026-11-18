# Requirement 4 — interview checklist image

## Source and conversion

- Source: `/home/amjad/Downloads/Telegram Desktop/photo_2026-11-17_17-00-42.jpg`.
- SHA-256: `e0dedbef9e9f7a8208c6e098dc75dcd31701591f7a77ca56f0607b29aefd124c`.
- Read visually and translated into English on 2026-11-17. This single image has no slide numbers; the identifiers below are assigned for traceability in [SPEC.md](../../SPEC.md#7-requirement-4--asynchronous-queues-and-real-time).
- The image agrees with [the lecture](requirement-4.md). It provides a concise interview checklist rather than new implementation choices.

## Translated requirements

**Title:** Requirement 4: asynchronous processing and real-time updates, called Asynchronous Queues & Real-Time.

**Goal:** work the user need not wait for happens in the background, while changes relevant to them reach their screen immediately.

| ID | Image instruction |
| --- | --- |
| I4-01 | Publish an event in RabbitMQ when an order is created; the application responds to the user without waiting. |
| I4-02 | An independent worker sends a real confirmation email through an external email service. |
| I4-03 | A message must not disappear if the worker fails. Retry delivery, and provide a separate DLQ for messages that always fail. |
| I4-04 | On the order tracking page, the customer sees status changes immediately, for example when an administrator approves the order, without refreshing. Nobody else may track this order. |
| I4-05 | A new order appears immediately on the administrator dashboard, without refreshing. Only users with the administrator role may enter this dashboard. |
| I4-06 | Verify identity and permission before subscribing to either of the two channels. |

## Explicit demonstration requested

**I4-D1:** stop the worker and start it again, without losing any email.

**I4-D2:** show a new order appearing immediately on the administrator dashboard.

**I4-D3:** show administrator approval appearing immediately on the customer's page.

## Reading the image together with the lecture

- The order response must not wait for the email worker or email provider. Lecture slide 6 places normal RabbitMQ publication before the order-created response.
- A logged email or local mail capture alone does not satisfy the explicit real/external-email requirement.
- Worker restart, retries, and the DLQ are distinct behaviors: an unavailable worker leaves work pending, a transient delivery failure is retried, and permanent failure is isolated.
- Both real-time directions must be shown with already-open browser pages; a successful REST response alone is insufficient.
- Interface visibility is not subscription authorization. The backend must reject unauthorized subscriptions as well as enforce existing HTTP ownership/roles.

These last distinctions explain how to assess the source requirements; detailed recovery mechanisms and test procedures are repository decisions in SPEC.md.
