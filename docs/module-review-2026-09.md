# September 2026 review — completed in 3.19.0

The examination of module 3.18.0 is closed with the implementation in 3.19.0. The [operations guide](operations-3.19.md) records the final behavior, migration steps, verification and consistency limits. The original findings and implementation history remain available in the task and Git history where committed.

| Finding                                                              | Implemented resolution                                                                      |
| -------------------------------------------------------------------- | ------------------------------------------------------------------------------------------- |
| OR scopes could bypass the SSR public guard                          | Explicit grouping through the shared filter composer; real-server regression                |
| Private metadata and linked authorities could enter public documents | Public value/title/link projection with an explicit restricted-OCR excerpt exception        |
| Authority changes left stale entity and referring content documents  | Authority-aware snapshots and reverse-dependency journaling                                 |
| Overlapping rebuilds and cleanup could remove needed generations     | Database locks, unique names, explicit retention and recorded rollback targets              |
| Failed replay could still report rebuild success                     | Replay and count reconciliation before promotion; independently attempted alias restoration |
| Import response totals did not establish completeness                | Exact per-document outcome validation and source/server count checks                        |
| Public results carried unnecessary vectors                           | Key-enforced projection and bounded excerpts                                                |
| Supporting requests diverged from the main query                     | Shared exact/stopword policy across results, counts, facets, map and export                 |
| Inline HTTP indexing delayed saves and lost outage work              | Durable journal, background draining, retry/status tools and scheduled recovery hook        |
| Page-local evidence misclassified semantic-only queries              | Query-wide keyword-only counts                                                              |
| Auxiliary requests polluted analytics                                | Analytics disabled for supporting traffic; explicit browser search-outcome event            |
| Dependency resolution and framework contracts lacked reproducibility | Committed Composer lock and real Omeka 4.2.1/Typesense 30.2 integration CI                  |

Local verification: 244 client tests, 275 PHP tests (842 assertions), lint, type checking, PHPStan, PHP syntax, both production bundles and the real-server failure/recovery suite passed. The benchmark harness passed its disposable-fixture smoke test; it does not establish production performance.

Deployment is separate from implementation. Module upgrade, full reindex, old-key revocation, queue scheduling and live acceptance checks remain in the [deployment checklist](deploy-checklist.md). Corpus-dependent relevance/SQL tuning and intentional product deferrals remain in the [engineering roadmap](engineering-roadmap.md) and [product roadmap](../ROADMAP.md).
