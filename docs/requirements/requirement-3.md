# Course presentation: Requirement 3

## Source and conversion

- Source: `/home/amjad/Downloads/Telegram Desktop/مشروع_تطبيقات_الإنترنت_–_المتطلب_الثالث.pptx`
- Course: Internet Applications, Damascus University, Faculty of Informatics Engineering, academic term 2026.
- Converted: 2026-11-10. All 12 slides were read visually: the PPTX contains slide images with no extractable text.
- This is an English translation of the substantive slide content, preserving slide order, lists, diagram relationships, and evaluation instructions. Repeated footers, decorative graphics, and the university logo are omitted. It is not a verbatim transcription of the Arabic text. Implementation choices belong in [SPEC.md](../../SPEC.md).
- Repository scope: a later milestone, after requirements 1 and 2 are verified. A slide describing earlier milestones as completed is course context, not evidence that this checkout satisfies them.

## Slide 1 — Performance & Caching

Requirement 3 of the Distributed Real-Time E-Commerce Platform.

## Slide 2 — Where we are

The application works; now make it faster.

| Number | Requirement | Timing in this presentation |
| --- | --- | --- |
| 1 | API design and security | Completed |
| 2 | Inter-service communication | Completed |
| 3 | Performance and caching | Today |
| 4 | Asynchronous processing and real-time behavior | Later |
| 5 | Scaling and load distribution | Later |
| 6 | Monitoring and fault tracing | Later |

## Slide 3 — Goal

Make the system faster and prove it with numbers.

## Slide 4 — Golden rule

Measure first, improve, then measure again:

1. Measure: identify slowness using numbers rather than guesses.
2. Improve: change one thing at a time.
3. Measure again: compare before and after and explain the difference.

An improvement without before-and-after numbers does not count.

## Slide 5 — Four optimization layers

```text
Browser / HTTP caching
          |
Server / Redis caching
          |
Database / indexes and faster queries
          |
Read replica / distribute reads to a second copy
```

The closer to the user a layer can answer the request, the faster the response.

## Slide 6 — Database: where is the slowness?

- Use realistic data volumes: hundreds of thousands of synthetic orders so that slowness actually becomes visible.
- Analyze queries using `EXPLAIN` to identify and then improve slow queries.
- Find and fix the N+1 problem: issuing one query per item instead of a query for the whole collection.
- Discussion question: an index speeds up reads; what does it slow down?

## Slide 7 — Read replica

Provide a second database copy for reads only:

```text
Primary database -- automatic replication --> Read replica
   all writes                                  reads
```

The application writes to the primary database and reads from the replica.

Challenge: the replica lags slightly behind the primary. Ensure users can see what they just created.

## Slide 8 — Redis caching

Cache frequently read data, such as product lists, and remove cached data when it changes.

- Choose what to cache.
- Give each key a defined lifetime (TTL).
- Invalidate cached data when the underlying data changes so users do not see old values.
- Discussion question: what may be cached, and what must not be cached?

## Slide 9 — HTTP caching

Do not send the same data again when it has not changed:

1. Initial request: the client requests a product; the server responds with data and an `ETag` fingerprint.
2. Subsequent request: the client sends the fingerprint and asks whether anything changed.
3. Unchanged: respond `304` with no data.
4. Changed: respond with new data and a new fingerprint.

HTTP caching saves network traffic, especially for mobile clients and slow internet connections. Redis instead reduces database work.

## Slide 10 — Load testing: required numbers

| Metric | Before optimization | After optimization |
| --- | --- | --- |
| Response time p50 | Measure | Measure |
| Response time p95 | Measure | Measure |
| Response time p99 | Measure | Measure |
| Requests per second | Measure | Measure |

- p50: half of requests are faster than this value.
- p95: 95% of requests are faster than this value.
- p99: 99% of requests are faster than this value.
- Use k6 or an equivalent tool. Use the same test before and after optimization.

## Slide 11 — Required proof

Measure before and after optimization and explain what happened:

- Load tests measure p50, p95, and p99, with an explanation of observed system behavior.
- A product modification appears immediately to the user despite caching and the read replica.

## Slide 12 — Closing

Good luck; questions. Internet Applications project, Damascus University, 2026.
