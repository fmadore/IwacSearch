# Engineering follow-ups

Updated for 3.19.0. The July and September implementation work is complete. The [September closure report](module-review-2026-09.md) and [operations guide](operations-3.19.md) replace the old phased implementation plan. PHPStan is a blocking CI gate; SQL/event/key/rebuild contracts have a real Omeka/Typesense integration lane.

## Remaining measurement and operational work

- Run judged, representative queries through the benchmark harness before changing token dropping, reranking, embedding models or long-document chunking. Record latency, response size and relevance together. The disposable-fixture run only validates the harness.
- Measure source SQL with EXPLAIN and realistic catalog sizes before adding core database indexes or making further reader changes.
- Complete the [deployment checklist](deploy-checklist.md), including live UI acceptance, key rotation and queue monitoring. These checks have not been claimed complete by local tests.
- Choose a withdrawal-latency target and monitor oldest pending changes. Indexing is eventually consistent; direct SQL/event-disabled writes and the new-resource commit/post-event crash gap require reconciliation by rebuilding.
- If production JavaScript diagnostics require source maps, design private artifact storage and retention together with the committed-bundle reproducibility check.

## Deliberate design decisions

- Entity occurrence aggregates refresh on full rebuild; incremental processing refreshes authority metadata and visibility.
- Two alias swaps are individually atomic, with guarded restoration; Typesense offers no two-alias transaction.
- Keep the two small schema definitions explicit and protected by drift tests. A generator is unwarranted while their shared fields are small and title stemming intentionally differs.
- Result-card derivations are extracted and tested. Separate list/gallery components would duplicate their largely shared layout and CSS.
- Do not add an unrestricted admin search key without a concrete product requirement.
- Omeka supplies Laminas and framework interfaces. Do not add direct Laminas/PSR module dependencies to simplify standalone tools or tests.

Product and infrastructure decisions are tracked in [ROADMAP.md](../ROADMAP.md).
