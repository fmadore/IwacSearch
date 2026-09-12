# IwacSearch 3.19 operations and implementation

This release implements the September module review. It requires an Omeka module upgrade and a full reindex to apply the metadata projection to existing documents. The schema stays at `iwac_v8`; the mapped document fields have not changed.

## Deployment

1. Install the release files and run `composer install --no-dev --optimize-autoloader` from the committed lockfile. It resolves the existing supported dependency lines against PHP 8.2. New major releases that require a higher PHP floor are deliberately excluded. No Laminas or PSR package is a direct module requirement.
2. Run the Omeka module upgrade. It creates the `iwac_search_change` ID journal and `iwac_search_rollback` previous-target record. Do this before resuming catalog writes.
3. Replace a mounted search-parent secret with a key whose actions are exactly `documents:search` and whose collections match `['^iwac_current$', '^iwac_index_current$']`. The module now validates mounted-secret metadata and rejects a wider scope. Settings-backed installs mint a new scope-specific parent automatically. Custom alias deployments must configure their matching anchored expressions.
4. Reindex through the maintenance page or `php cli/reindex.php`. Check the job log, `verified_counts`, and `catch_up.ok`. Keep public search in maintenance during the first privacy migration if existing indexed private values must cease to be exposed immediately; the code change cannot erase the outgoing index by itself.
5. Revoke the old parent key after migration, including parents that allowed direct reads of versioned collections. Already-issued old scoped keys otherwise retain their original restrictions until they expire. Purge any external page cache containing old bootstrap JSON; the changed SSR request policy naturally uses a new internal cache key.
6. Schedule `php cli/maintenance.php drain` every minute in the hosting stack. Normal successful API writes also dispatch an Omeka job, and a healthy worker chains another job when its two-minute budget leaves a backlog. The scheduled run is the recovery path for dispatcher failures, failed requests, process crashes, and Typesense outages. No scheduler or production secret was changed by this implementation.

Use the existing `IWAC_OMEKA_VENDOR`, `IWAC_OMEKA_DB_INI`, and `IWAC_TYPESENSE_*` CLI settings. Load Omeka's vendor before the module's vendor.

## Consistency and recovery

API pre-events acquire a database-scoped advisory mutation lock and persist affected existing IDs and reverse dependencies before deletes can remove their links. Post-events append the resulting IDs, including raw Omeka entities from create/batch-create responses, and schedule work. Items, media moves, and item-set membership cascades share this path. Reads do not construct the indexing services.

Workers acquire the mutation lock followed by the publication lock. They read at most 50 journal rows and map a source snapshot, then release the mutation lock before HTTP/embedding work. This lets catalog saves proceed during imports. A duplicate worker yields immediately when another worker owns publication; it never holds the mutation gate while waiting for HTTP work. Publication stays serialized. Acknowledgments target only the exact journal rows consumed; later updates cannot be swallowed. Failed writes throw and remain pending. Ordinary search indexes are eventually consistent: visibility changes take effect when the worker applies them. Authority changes are applied before content embedding work. Monitor backlog age if withdrawal latency matters.

The pre-journal protects existing-resource updates/deletions even if a process dies before the post-event. A newly created resource whose process dies between the database commit and the post-event can require a rebuild to discover its ID. Direct SQL changes and API callers that deliberately disable initialization/finalization events bypass the journal and require a full rebuild. These are explicit limits of the Omeka event boundary.

Bulk rebuilds share a cross-process rebuild lock. Names contain UTC time and a random suffix. After streaming content, cutover holds the mutation/publication locks, replays journal IDs, reconciles final source and server counts by content type, and rebuilds entity aggregates from that final source. Any rejected document, malformed/missing import outcome, failed replay, cancellation, or count mismatch prevents promotion. The two alias updates are individually atomic; they are not a two-alias transaction. If either fails, both restoration attempts run and any rollback failure is logged as critical with the alias and prior target.

Read `php cli/maintenance.php status` or the admin maintenance page for pending count/oldest timestamp. Use its retry button or `drain` after resolving an outage. Omeka job logs retain the failure state; the module does not log rejected document bodies or OCR. Create/upgrade grants need permission for the module's journal table; advisory locks require MySQL/MariaDB `GET_LOCK` support.

## Retention

Promotion never sweeps other builds or deletes the previous generation. Run `php cli/maintenance.php prune` or the maintenance cleanup button periodically. Cleanup takes the rebuild lock, protects every current alias target and the recorded outgoing targets of the last successful promotion, keeps the newest inactive generation of each collection family, and only removes owned timestamped generations older than seven days. This consumes extra disk/RAM; provision capacity for live, retained, and building generations. Replaying current journal changes into an older collection is necessary before using it as a manual rollback target after subsequent catalog edits.

Processed journal rows are retained for seven days and pruned only under the rebuild lock. Entity occurrence statistics intentionally refresh at a bulk rebuild; incremental authority metadata and visibility refresh immediately when consumed.

## Public search behavior

Public property projections exclude private literals and private linked resources. Source reads derive titles from public `dcterms:title` values rather than Omeka's unfiltered cached title. Restricted `bibo:content` remains searchable under the existing excerpt policy. Public keys force exclusion of OCR, TOC and embedding vectors, restrict full highlights to title, and bind snippet threshold/affix parameters. Real Typesense 30.2 tests verify that callers cannot widen those parameters, that excerpts still render, and that retained collections cannot be searched directly with the new parent scope.

One query policy supplies exact syntax, stopwords and field projection for main results, counts, facets, histogram, map and export. Histograms deliberately omit the selected year interval. Failed histograms remain unavailable with retry rather than being cached as empty. Semantic-only detection uses a keyword-only count independent of the visible page; missing count evidence never proves that nothing matched.

Supporting requests disable Typesense analytics. Native `nohits_queries` still measures server zero-hit queries, not the UI's semantic-only state. The browser emits `iwac-search:outcome` with `{query, collection, found, keywordFound, semanticOnly}` for hosts that want to measure that distinction. It does not send a new telemetry stream. Deduplicate page changes in any host analytics listener.

SSR cache keys include journal/applied-change watermarks and promotion records, so completed background changes invalidate prior snapshots. SSR is adopted only for matching query/page/sort/filter/year/page-size state; matching full-mode hydration fetches its missing histogram separately. Browser requests have a 15-second deadline, map loops cancel between pages, and rejected authorization refreshes once. The PHP SDK receives a real Guzzle transport with 2-second connection timeout, 4-second web timeout/no retries, and 120-second CLI timeout/two retries.

## Verification and measured tuning

Run `npm run lint`, `npm run check`, `npm test`, `npm run build`, `composer validate --strict`, PHPUnit, PHPStan and PHP syntax checks. CI includes the real Omeka 4.2.1/Typesense 30.2 integration fixture:

```bash
sudo bash tests/integration/setup-local.sh
sudo php tests/integration/rebuild.php
```

The fixture is hardcoded to disposable localhost port 18108, a disposable key, and `iwac_search_test`. It resets that database using Omeka's official schema and uses Omeka's real DBAL, API request, response and entity classes. It tests privacy, authority changes, raw create responses, journal acknowledgments, competing locks, live key restrictions, real embedding reindexes, mid-build edits/deletions, injected replay failure/recovery and retention. It covers module install/upgrade and real event wiring, but is not a full browser acceptance test.

Local validation on 12 September 2026: 244 client tests, 275 PHP tests (842 assertions), Svelte checking, lint, PHPStan, PHP syntax and both production bundles passed. The complete real-server integration suite passed, including injected replay failure and recovery. The benchmark harness completed 12 disposable-fixture measurements; these are a tooling smoke test, not production performance evidence.

For representative corpus measurements, copy `data/relevance-cases.example.json`, add judged `expected_ids`, and run:

```bash
IWAC_BENCH_ENDPOINT=https://your-staging-search \
IWAC_BENCH_KEY_FILE=/path/to/search-key \
node scripts/benchmark-search.js judged-cases.json > benchmark.json
```

The harness compares baseline, disabled token dropping and hybrid reranking, recording warmups, p50/p95 latency, bytes, recall@10 and reciprocal rank. Empty judgments produce null relevance metrics, not invented scores. Use staging for mixed import/search load measurements. No embedding-model, chunking, threshold or reranking default changed: test summary/chunk collections against judged long-document queries before changing document counts or the semantic representation. E5's 512-token input limit remains a model limitation. SQL now caches term IDs and fetches only the first public thumbnail; no indexes were added to Omeka core tables. Measure `EXPLAIN` and latency on a representative database before further tuning.

References: [Typesense key scopes](https://typesense.org/docs/30.2/api/api-keys.html), [imports](https://typesense.org/docs/30.2/api/documents.html), [vector search](https://typesense.org/docs/30.2/api/vector-search.html), [search parameters](https://typesense.org/docs/30.2/api/search.html), [E5 limitations](https://huggingface.co/intfloat/multilingual-e5-small#limitations).
