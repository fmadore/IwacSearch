# IwacSearch module review — 27 September 2026

> **Implemented in 3.20.0** (and the matching IWAC-docker commit), except
> where the status table says otherwise. Findings were made against module
> **3.19.1** and IWAC-docker `13a4574`, cross-checked with the Typesense
> **30.2** documentation. The resulting behaviour and deployment steps are
> in [operations-3.20.md](operations-3.20.md); the findings below keep their
> original wording as the rationale.

## Implementation status

| # | Status |
| --- | --- |
| A1 | Done — `Indexer\WriteGate` (per-request holds, released when the request is gone; settled on reads); unit tests |
| A2 | Done — phased cutover with a second quiescent watermark; one faceted count; `gate_held_seconds` stat. Fail-fast save wait not needed with the shorter window |
| A3 | Done — unpromoted builds dropped on any failure (never one an alias still serves) |
| A4 | Done — `DrainJobHistory::isActive()` gate on dispatch; completed drain jobs pruned after 7 days |
| A5 | Done — filtered delete + export-based aggregate read per batch; unit tests |
| B1 | Done — `limit_multi_searches: 10` in every key; client guard + drift check. `limit_hits` deliberately off |
| B3 | Done — APCu memo of secret validation; IWAC-docker `limit_req` on `/discovery/token` |
| B4 | Done — read-only build job + write-scoped publish job; all actions SHA-pinned |
| B5 | Done — IWAC-docker `/search-api/` allow-list (multi_search, collection search, health) |
| B6 | Done — `/health` probe; CORS off; comment corrected |
| C1 | **Not adopted** — facet sampling can drop rare entities from suggestions; `use_cache` left for measurement |
| C2 | Done — `sessionStorage` key cache (tab-scoped, rejected keys dropped) |
| C3 | Done — one `PropertyValues` per row; one-query epoch, skipped when the cache is off; block SSR skipped on its own deep links (and a PHP dotted-key bug fixed for `/search`); flagged intro purification with upgrade migration |
| D1 | Done — IWAC-docker `search-worker` (drain every minute, prune daily) |
| D2 | Done — generations, alias targets and Typesense memory on the maintenance page |
| D3 | Done — `IwacInstance::CONTENT_ALIAS` / `INDEX_ALIAS`; config keys removed |
| E1 | Done — `IncrementalIndexer::create()` |
| E2 | Done — shared write-op constant, dead `promote()` params and scope constant removed, `ObjectNotFound`-only catch, shared stopword constant, `App.svelte` pristine predicate, `<noscript>` link |
| E7 | Done — plus `npm run lint:docblocks` to keep it that way |

## How this was checked

- Read every PHP class, the CLI entry points, both schemas, the client's
  request layer (`typesense.ts`, `suggestQuery.ts`, `transport.ts`,
  `scopedKey.ts`, `App.svelte`), and IWAC-docker's compose file and nginx
  proxy.
- Confirmed Omeka's event contract against the Omeka S 4.2.1 source
  (`Omeka\Api\Manager::execute()`).
- Typesense: v30.2 is still the newest server tag. typesense-php 6.0.0 is the
  newest stable release, and 6.1.0 (the filter-escape helper in ROADMAP.md)
  is still at RC2. Documentation was read from the 30.2 docs
  (typesense.org/docs/30.2). typesense.org itself was blocked by the review
  environment's network policy, so the pages came from a mirror of that
  version.
- Baseline and post-change results: 275 PHPUnit tests (842 assertions), 244
  Vitest tests, `npm run lint`, `svelte-check` (0 errors) and `npm run build`
  all passed. The rebuilt `asset/dist` is byte-identical to the committed
  bundles. **Not run:** PHPStan (its GitHub-hosted download was blocked) and
  the real Omeka/Typesense integration suite (needs root, MySQL and a
  Typesense binary).

## Summary

| #   | Priority | Finding                                                                             | Area              |
| --- | -------- | ----------------------------------------------------------------------------------- | ----------------- |
| A1  | High     | A write that throws between pre/post events leaves the mutation gate held           | Write path        |
| A2  | High     | Rebuild cutover does its slowest work under the gate every save waits on            | Rebuild           |
| A3  | Medium   | Failed builds are never dropped and stay in Typesense RAM for 7+ days               | Rebuild/retention |
| D1  | Medium   | The per-minute recovery drain is not scheduled in IWAC-docker                       | Operations        |
| B1  | Medium   | Public key does not embed `limit_multi_searches` (docs-recommended)                 | Security          |
| B3  | Medium   | `/discovery/token` is unthrottled, and a mounted secret adds an admin call per mint | Security/perf     |
| A4  | Medium   | Every write request spawns a PHP job process and a job row                          | Write path        |
| A5  | Medium   | Incremental apply issues one HTTP call per document for deletes and entity reads    | Indexer perf      |
| B4  | Low–Med  | Release workflow grants `contents: write` to every step; actions pinned by tag      | Supply chain      |
| B5  | Low      | nginx `/search-api/` is a deny-list; the browser needs only two endpoints           | Defence in depth  |
| B6  | Low      | Typesense health check is TCP-only; CORS enabled although traffic is same-origin    | Operations        |
| C1  | Low–Med  | Typeahead runs up to 5 whole-corpus facet searches per keystroke                    | Search perf       |
| C2  | Low      | Scoped key is re-minted on every page load (no session persistence)                 | Client perf       |
| C3  | Low      | Smaller efficiency nits (SQL value objects, SSR epoch, count queries, block SSR)    | Perf              |
| D2  | Low      | Maintenance page cannot show generations or Typesense memory                        | Operations        |
| D3  | Low      | `collection_alias` config is honoured by the web factories only                     | Config            |
| E1  | Low      | Incremental indexer graph is wired in three places                                  | Refactor          |
| E2  | Low      | Smaller refactors (write-op list, dead params, catch scope, stopword name, App)     | Refactor          |
| E7  | Done     | Orphaned/stale docblocks and comments                                               | Docs (fixed here) |

---

## A. Write path and rebuild

### A1. The mutation gate leaks when a write fails between its pre and post events

`Module::attachListeners` acquires the advisory mutation lock on
`api.execute.pre` (`ItemEventListener::onBeforeWrite`, `acquire(300)` at
line 39) and releases it on `api.execute.post` (`onAfterWrite`, line 122).
In Omeka 4.2.1, `Manager::execute()` runs `initialize()` (pre), then the
adapter operation, then `finalize()` (post), with no `try/finally`. Post
never fires when:

- the adapter throws: a `ValidationException` on a missing required value,
  a `NotFoundException`, or an entity-level permission denial inside
  `update()`/`delete()`;
- a lower-priority `api.execute.pre` listener from another module throws;
- a caller passes `finalize => false` but leaves `initialize` on. The
  operations guide covers callers that disable both events, but disabling
  only finalization leaks the gate.

`DatabaseLock` is re-entrant by depth, so the next write in the same process
takes the depth to 2 and its post releases it back to 1. **The lock then stays
held until the PHP process exits.** In a web request that is the end of the
request, which is harmless. In a long-running job that catches per-row
exceptions and continues (BulkEdit, CSV Import update mode, any in-house
script), the gate is held for the rest of the job:

- `ChangeDrainer::run()` finds the gate taken (`tryAcquire`) and returns 0, so
  indexing stalls for the whole job;
- every other process's save waits in `GET_LOCK(…, 300)`. That equals nginx's
  `fastcgi_read_timeout 300` in IWAC-docker, so the editor sees a five-minute
  hang, then a 504, and the write fails with `IwacSearch operation already
  running`.

`$this->affected[spl_object_id($request)]` also keeps one entry per failed
write. That is harmless while pre always overwrites before post reads, but it
is the same bookkeeping problem.

**Fix:** track open writes by request object instead of by depth alone:

```php
/** @var \WeakMap<object, list<int>> writes between pre and post */
private \WeakMap $open;
private int $held = 0;

// onBeforeWrite: $this->releaseAbandoned(); acquire; $this->held++; $this->open[$request] = $affected;
// onAfterWrite:  $ids = $this->open[$request] ?? null; unset(...); ...; release once if $ids !== null; $this->releaseAbandoned();

private function releaseAbandoned(): void
{
    // A request whose adapter threw is unreachable once its caller has
    // handled the exception, so its WeakMap entry is gone. Release the depth
    // it still holds.
    while ($this->held > count($this->open)) {
        $this->gate->release();
        $this->held--;
    }
}
```

This also replaces the `spl_object_id` map. Caveat: with
`zend.exception_ignore_args=Off`, a caught exception's trace keeps the
`Request` alive until the exception variable is reassigned. That delays the
release by one exception but no longer makes it permanent. Add a unit test
(the pre/post pair with a throwing middle) and run the integration suite.

### A2. The rebuild cutover runs its slowest steps while holding the gate

`ReindexOrchestrator::build()` takes the mutation and publication locks
(lines 78–80) and, **while every catalog save waits on them**:

1. replays the changed IDs, which triggers Typesense auto-embedding
   (E5 inference on the 4-CPU container) for each replayed content document;
2. rebuilds the full entity authority from SQL (line 99);
3. streams and maps **the entire content corpus** a second time (metadata
   only, line 105) to recount types and occurrences;
4. runs one count search per content type (line 119);
5. creates and imports the **whole entity collection** (`IndexReindexer`,
   line 128);
6. promotes both aliases.

Steps 2–5 scale with corpus size, not with the number of edits made during
the build. A save that arrives meanwhile blocks in `onBeforeWrite` for the
whole window. Past 300 s it fails, with nginx timing out at the same moment
(see A1).

**Fix (keeps the correctness contract):**

- Before taking the gate, capture a second watermark `W2`, then run steps
  2–5 unlocked. They produce a candidate entity collection and per-type
  counts as of `W2`. Entity occurrence statistics already refresh only on
  full rebuild by design, so a few seconds of lag is within contract.
- Under the gate, replay only `changedSince(W2)` into both new collections.
  Adjust the expected per-type counts by re-mapping only those IDs
  (before/after presence), verify, and promote.
- Replace the per-type searches with one `facet_by: type_s, per_page: 0`
  search.
- Log the gate hold time in the job stats (`gate_held_seconds`), so the
  window is measured rather than guessed.

If the redesign waits, at least make `onBeforeWrite` fail fast with a
readable message during a rebuild (a short wait plus "search index rebuild in
progress, retry in a few minutes") rather than hanging for the full nginx
timeout.

### A3. Failed builds are never dropped

A build that fails **after** its import (a rejected document at
`Reindexer.php:134`, or a replay, count or entity failure in the
orchestrator) throws without `safelyDropCollection()`. Only an exception
thrown _during_ import drops the half-built collection (line 128).
`CollectionRetention` then keeps the newest inactive generation and anything
younger than 7 days, so every failed attempt in a week stays resident.

Typesense keeps collections in memory, and each content generation carries
the OCR text index plus 384-float vectors per document. IWAC-docker caps
Typesense at **4 GiB**. The live generation, the recorded rollback
generation, a build in progress and a few failed retries can exhaust that,
and an OOM kill takes public search down.

**Fix:** a collection that was never promoted cannot be a rollback target:
rollback targets are recorded separately in `iwac_search_rollback`. Drop both
new collections in the orchestrator's failure path, using
`safelyDropCollection` after the alias-restore attempts. If keeping one for
debugging is wanted, keep at most one failed build, not 7 days of them.

### A4. Every write request spawns a job

`onAfterWrite` registers a shutdown function that dispatches
`DrainChanges` once per request (line 113). With Omeka's default `PhpCli`
strategy, each dispatch inserts a `job` row and `exec()`s a background
`php perform-job.php` that bootstraps all of Omeka. Under normal pacing each
job drains that one save's rows; during a burst of saves most of them find
another drainer holding the lock and exit at once. Effects:

- one job row per save (the admin Jobs list fills with DrainChanges entries,
  and the table grows without bound);
- one extra full Omeka bootstrap per save;
- the dispatch runs before the response is sent (Omeka does not call
  `fastcgi_finish_request()`), which adds latency to every save.

**Fix:** skip dispatch when a `DrainChanges` job started in the last few
minutes is still `starting`/`in_progress`. A single indexed query on `job`
suffices. The running worker already chains itself while a backlog remains,
and the scheduled drain (D1) is the backstop. Periodically purge completed
DrainChanges rows as part of `prune`.

### A5. Incremental apply makes one HTTP call per document

`IncrementalIndexer::applySnapshot()` calls, per changed ID:

- `deleteDocument(content, id)` when the ID maps to no content document —
  including **every authority record**, which is never content (line 83);
- `deleteDocument(index, id)` for **every content item**, which is never an
  entity (line 91);
- `document(index, id)`, a GET, for every entity, to preserve its aggregates
  (line 94).

An authority edit that cascades to 500 referring articles therefore makes
about 500 404-returning DELETE calls on the entity index. Typesense 30.2
supports deleting by filter (`DELETE …/documents?filter_by=id:[a,b,c]`),
which tolerates absent IDs, and reading many documents in one search
(`filter_by: id:[…]`, `include_fields: frequency,authored_count,…`,
`per_page: 250`).

**Fix:** per batch, make one filtered delete per collection and one batched
read of the existing entity aggregates. That turns O(n) round trips into
O(1) per batch of 50. `deleteItem()` can use the same helper.

---

## B. Security hardening (Typesense 30.2 docs)

### B1. Embed `limit_multi_searches` in the public scoped key

The [API-key](https://typesense.org/docs/30.2/api/api-keys.html) and
[multi-search](https://typesense.org/docs/30.2/api/federated-multi-search.html)
docs recommend embedding `limit_multi_searches` (default **50**) in public
scoped keys. nginx's `limit_req zone=search` counts HTTP requests, but one
`multi_search` request can carry 50 searches, so the 10 r/s per-IP budget
becomes up to 500 searches/s against a 4-CPU container.

The client never sends more than **7** searches in one request: suggest is
1 title search, up to 5 facet searches and 1 entity search. Search is at most
3, counts 4, union 2. **Embed `'limit_multi_searches' => 10` in
`TypesenseSearchKeyProvider::mintPublicScopedKey()`**. Do not add it to
`PublicSearchPolicy`, whose parameters are also spread into each SSR
sub-search body. This tightens rather than loosens the key, but verify it
with the real-server key tests before shipping.

`limit_hits` is the docs' other anti-scraping lever. It is rightly **not**
embedded: deep pagination is a deliberate product choice, and the same
metadata is published openly on Hugging Face. Record that decision next to
the key so it isn't "fixed" later. `search_cutoff_ms` (which bounds text
matching but not faceting) is an optional per-query cost ceiling if abuse
ever appears.

### B3. `/discovery/token` has no rate limit; a mounted secret costs an admin call per mint

Each token request boots Omeka in PHP-FPM, and IWAC-docker has no
`limit_req` on `/discovery/token`, unlike `/search-api/`, `/login` and
`/mcp`. When the operator follows the "mount the parent key as a secret"
hardening, `resolveSearchOnlyKey()` also calls `validateSecretScope()` on
**every** mint (line 127). That is a `GET /keys` with the admin key, which
lists every key on the server. It adds latency, and it makes token minting
(a local HMAC) depend on Typesense being up.

**Fix:**

- IWAC-docker: add an exact-match `location = /discovery/token` that
  includes the index.php FastCGI block, with a modest `limit_req`. Place it
  per the regex-ordering rules in IWAC-docker's CLAUDE.md, and apply it with
  `--force-recreate`.
- Module: memoize a successful validation in APCu, keyed by
  `sha256(secret ‖ scope)`. Typesense keys are immutable, so a given key's
  scope cannot change after validation. A rotated file gives a new hash and
  is revalidated at once.

### B4. Release workflow permissions and action pinning

`.github/workflows/release.yml` sets `permissions: contents: write` for the
whole job, including `npm ci`, `npm run build` and `composer install`, which
execute third-party code. Actions are pinned by tag (`actions/checkout@v7`,
`shivammathur/setup-php@v2`), while IWAC-docker enforces commit-SHA pinning in
`tests/test_security_invariants.py`.

**Fix:** split the release into a read-only build job that uploads the zip
as an artifact and a minimal publish job holding `contents: write`. Pin
third-party actions to SHAs; Dependabot's `github-actions` ecosystem keeps
SHA pins current.

### B5. Make `/search-api/` an allow-list

The browser only ever calls `POST /search-api/multi_search`. nginx
currently forwards everything under `/search-api/` except six denied
prefixes. `/analytics/*`, `/presets`, `/stopwords`, `/synonym_sets`,
`/curation_sets`, `/conversations`, `/nl_search_models`, `/stemming` and
`/aliases` all remain reachable. Each needs the admin key, so this is
defence in depth only. An allow-list (`location = /search-api/multi_search`
plus `= /search-api/health`, everything else `deny`) states the contract
directly and stays correct as Typesense adds endpoints.

### B6. Typesense container settings

- The health check only opens a TCP socket, so it passes whenever the port
  is bound. `GET /health` reports `{"ok":true}` only for a healthy node, and
  the 30.2 docs say it surfaces `resource_error: OUT_OF_MEMORY` /
  `OUT_OF_DISK`. The memory case is exactly the failure to watch under the
  4 GiB cap (A3). Confirm on the live container that it also reports
  not-ready while collections load after a restart. With bash but no curl in
  the image:
  `exec 3<>/dev/tcp/localhost/8108 && printf 'GET /health HTTP/1.0\r\n\r\n' >&3 && grep -q '"ok":true' <&3`.
- `TYPESENSE_ENABLE_CORS: "true"` is unnecessary, because the browser
  reaches Typesense same-origin through nginx (the nginx comment says so).
  Disabling it removes `Access-Control-Allow-Origin: *` from proxied
  responses.
- The comment above `TYPESENSE_API_ADDRESS: 0.0.0.0` says it refuses
  non-proxy traffic. It does the opposite: it binds all interfaces. The
  isolation comes from the network layout, not this setting.

---

## C. Efficiency

### C1. Typeahead cost per keystroke

`runSuggest()` sends one title search, **up to five `q=*` facet searches
with `facet_query`** (each computes facets over every public document), and
one entity search. That is per keystroke, per visitor, from every public page
via the header bundle. Typesense 30.2 offers two levers worth measuring with
the existing benchmark harness:

- `facet_sample_percent` / `facet_sample_threshold` on the facet
  sub-searches. Typeahead counts only order the rows, so approximate counts
  are acceptable.
- Server-side caching: `use_cache=true`, which for `multi_search` must be a
  URL query parameter, with `cache_ttl`, which can only be set by embedding it
  in a scoped key. Popular prefixes repeat across visitors. Confirm on the
  live server whether scoped keys with different `expires_at` share cache
  entries before relying on it.

### C2. Persist the scoped key for the session

`scopedKey.ts` caches the key in module memory, so every page navigation
that searches mints a new key (a PHP-FPM Omeka bootstrap). The key is
public-shaped and valid for an hour. Storing it in `sessionStorage` behind
the existing `expires_at − 60 s` guard, with the storage access wrapped in
`try/catch`, removes most token requests.

### C3. Smaller items

- `OmekaSourceReader::streamDocs()`/`loadResources()` build
  `PropertyValues::fromRows()` twice per row (title and values). Build it
  once.
- `InitialResponseRenderer::renderMany()` evaluates the cache epoch (three
  SQL queries in `ChangeJournal::cacheVersion()`) before knowing whether the
  cache is enabled, so it pays them with APCu off or `ttl_seconds: 0`. The
  three queries can also be one.
- Page blocks SSR even when the URL carries that block's search state
  (`?b{id}.q=…`). The standalone route already skips SSR in that case
  (`requestCarriesSearchState()`).
- `IwacSearchBlock::render()` re-purifies `intro_html` on every public render
  to cover blocks saved before `onHydrate()` existed. Purify existing blocks
  once in `Module::upgrade()` and drop the render-time pass.
- Cutover per-type counts: one faceted search instead of N (A2).

---

## D. Operations and cross-repo

### D1. The recovery drain is not scheduled

The operations guide (step 6) and the deployment checklist require
`php cli/maintenance.php drain` every minute. It is "the recovery path for
dispatcher failures, failed requests, process crashes, and Typesense
outages". IWAC-docker has no scheduler for it: neither the `omeka-cli`
sidecar nor the compose file runs it. Until one exists, a failed shutdown
dispatch or a Typesense outage leaves changes pending until the next catalog
save happens to dispatch a job.

**Fix:** add the cron line to the host crontab or the `omeka-cli` sidecar
(`docker compose exec -T php php /var/www/html/modules/IwacSearch/cli/maintenance.php drain`),
plus a weekly `prune`. Alert when `status` reports an oldest pending change
older than the withdrawal-latency target.

### D2. Show generations and memory on the maintenance page

The page shows each alias's document count only. Given A3 and the 4 GiB cap,
add: each alias's concrete target, the retained generations with
`num_documents` and age (`GET /collections` with `exclude_fields=fields`
keeps the payload small), and Typesense memory from `GET /metrics.json`
(`typesense_memory_active_bytes`, `system_memory_used_bytes`).

### D3. `collection_alias` is only partly configurable

`iwac_search.typesense.collection_alias` / `index_collection_alias` are read
by the web factories, but `ReindexOrchestrator` (lines 61–62, 142–146),
`cli/maintenance.php drain`, `SchemaLoader::loadForReindex()`,
`IndexReindexer`, `AnalyticsSync` and the key scope all hard-code
`iwac_current` / `iwac_index_current`. Changing the config would make search
read an alias the rebuild never promotes. Either remove the knob (the module
is single-instance by design, per `IwacInstance`) or pass one config value
object through every entry point.

---

## E. Refactoring and code health

### E1. One builder for the incremental indexer

The incremental graph (reader, authority, country resolver, registry,
`CollectionOps`, `IncrementalIndexer`) is assembled in
`IncrementalIndexerFactory`, `cli/maintenance.php` and
`ReindexOrchestrator`. The CLI copy ignores the configured aliases (see D3).
Mirror what `ReindexOrchestrator` did for the bulk path: one static builder
that all three call.

### E2. Smaller refactors

- **Write operations listed three times**: `Module.php` (both closures) and
  `ItemEventListener::isWrite()`. Make it a public constant on
  `ItemEventListener`. Autoloading the class is cheap; resolving its service
  graph is what `Module` must avoid, and it still would.
- **Dead parameters**: `CollectionOps::promote()` takes `$baseName` and
  `$previous` "for compatibility", but it is module-internal. The orchestrator
  even re-parses both schema YAML files (`->load()['name']`) just to supply
  the unused argument. Remove both. `TypesenseSearchKeyProvider::TIGHTENED_COLLECTION_SCOPE`
  now equals the default and survives only for tests.
- **Catch scope**: `AnalyticsSync::ensureDestination()` catches every
  `Throwable` on `retrieve()`, so a transport error is treated as "absent"
  and followed by a create attempt. Catch `ObjectNotFound` only, as
  `CollectionOps` does.
- **Stopword set name** is `StopwordsSync::SET_NAME` in PHP but the literal
  `'fr_default'` twice in `typesense.ts`. Put it in the bootstrap, or add the
  pair to `check-schema-drift.js`.
- **`App.svelte`** has grown to 1,612 lines, up from 1,205 in July (about 800
  lines of script and 11 `$effect`s). Two quick wins: the "pristine initial
  state" predicate is written out twice (`initialResponse` and
  `skipNextFetch`), so extract `isPristine(initial)`; and `const client =
  $derived.by(() => new TypesenseClient(bootstrap))` is a workaround where a
  plain `const` with the same `svelte-ignore` as `provideI18n` states the
  intent.
- **`<noscript>`** in the block template links to `/search`, which also
  requires JavaScript. Either link somewhere that works without JS or drop
  the link.

### E7. Documentation drift (fixed on this branch)

Refactors had left docblocks detached from their code, and several comments
described pre-3.19 behaviour. Corrected here, comments only (no generated
output changed):

- Stacked or orphaned docblocks: `Module::upgrade()`,
  `TypesenseSearchKeyProvider::settingsKey()` (its docblock sat on
  `validateSecretScope()`), `InitialResponseRenderer::performSearches()` (a
  superseded copy above the current one), `IwacSearchBlock::render()`,
  `MaintenanceController::dispatchJob()` (its docblock sat on
  `retryChangesAction()`), `typesense.ts` (`searchFacetValues`'s docblock sat
  on `yearDistribution`, plus an orphaned `suggest` docblock), and
  `transport.ts` (`isAbortError`'s docblock sat on `AbortSlot`).
- Stale statements: SSR cache "TTL is the only invalidation" (the key has
  carried a journal epoch since 3.19); "exclude_fields ocr_text,toc_txt"
  (the embedding vector is excluded too); `IncrementalIndexer` "hooked into
  api.update.post"; `Reindexer` "drop the previous collection"; `BulkReindex`
  "half-built collection already dropped"; `SearchControllerFactory` "the
  default scope is wide"; the analytics server flags "need an IWAC-docker
  change" (compose already sets them); and a `Module.php` `@see` link to a
  non-existent IWAC-docker document.
- `InitialResponseRenderer::buildSearch()` repeated
  `highlight_full_fields`/`snippet_threshold` after spreading
  `PublicSearchPolicy::parameters()`, which already sets both to the same
  values. Removing the repeats leaves the request body byte-identical, so SSR
  cache keys are unchanged.

A small lint that flags a `*/` followed directly by `/**` in `src/**/*.php`
would stop the first class of drift from recurring. The repo already runs
three bespoke lint scripts.

---

## F. Typesense 30.2 cross-check: what is current

- **Versions**: server 30.2 (latest) and typesense-php 6.0.0 (latest
  stable). Keep the 6.1.0 filter-escape adoption item open until 6.1.0 is
  released.
- **Analytics rules**: `AnalyticsSync`'s payload (`type`, `collection`,
  `event_type: search`, `params.destination_collection`, `limit`,
  `capture_search_requests`) matches the v30 format. The alias-binding
  question in ROADMAP.md is still worth verifying on live traffic.
- **Curation sets / MMR diversity, synonym sets, union search**: usage matches
  the 30.2 API (`curation_sets` linked in the schema, `curation_tags` plus
  `diversity_lambda` at query time, union pagination in the URL).
- **Imports**: per-line outcome validation is already strict.
  `return_id=true` would put document IDs in the failure log without
  indexing into the batch by offset.
- **French stemming**: 30.2 supports custom `stem_dictionary` dictionaries,
  but on a field that has one, dictionary stemming replaces the algorithmic
  stemmer. A French lemma dictionary (for example generated from a lexicon)
  is therefore a relevance experiment for the benchmark harness with judged
  queries, not a drop-in. Keeping the English pipeline for accent folding
  remains correct, since 30.2 still offers no folding for `locale: fr`.

## Suggested order

1. **A1**, with a unit test, then the integration suite.
2. **D1** (schedule the drain) and **A3** (drop failed builds). Both are
   small and remove silent failure modes.
3. **B1** and the **B3** nginx limit: one line each plus verification.
4. **A2** (cutover redesign), measured with `gate_held_seconds` first.
5. **A4, A5, C1, C2**, then the E-items opportunistically with the next
   changes to those files.

## What is already in good shape

Worth keeping as-is: escape-then-restore highlight sanitising, the
`IwacBootstrapJson` flag set, lazy listener resolution on reads, keyset
streaming with loop-invariant statements, exact per-line import validation,
guarded two-alias promotion with recorded rollback targets, the drift and
i18n lint gates, and the committed-bundle reproducibility check (it
reproduced byte-for-byte here).
