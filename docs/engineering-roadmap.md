# Engineering follow-ups

## Done in 3.27.0: the cross-module review with DRESearch (2026-10-28)

The two modules were compared file by file; what DRESearch did better was
brought here, and the reverse there (DRESearch 1.33.0). Everything below
shipped in 3.27.0 (see the [changelog](../CHANGELOG.md) and
[operations-3.27.md](operations-3.27.md)), plus PHP_CodeSniffer (PSR-12) and
PHPStan level 8 against the released Omeka S 4.2.1 tree.

- **Done:** locked filters are checked before they reach the admin-key SSR; the
  drain removes a rejected document instead of stopping on it; uninstall
  revokes every cached search key; a missing `vendor/` degrades the module
  instead of the site; translated error states for mount, typeahead, export
  and map.
- **Static quality:** PHP_CodeSniffer (PSR-12) and PHPStan level 8 in CI;
  type-aware ESLint; `tests/client` type-checked, `noUncheckedIndexedAccess`;
  PHPUnit fails on deprecations and notices.
- **Indexing:** a generation ledger with orphan cleanup, automatic retention
  and a per-type shrink guard against the live alias; readiness wait before a
  rebuild; typed cancellation; drain dispatch through a lease (no lost
  wake-up); writers no longer queue behind one global lock; journaled ids
  hidden from the SSR and from freshly minted keys; dead code and vacuous tests
  removed.
- **Omeka integration:** the block form moves to a view partial, the block
  becomes templateable and searchable (`getFulltextText`); public ACL actions
  in a tested constant; locale route aliases generated; a health endpoint and a
  request-id error envelope; PHP strings extracted to `template.pot` with a
  coverage check.
- **Client:** Playwright + axe tests against the built bundle; heading levels
  from context; did-you-mean guarded by the sequence ticket; `history.state`
  preserved.
- **Release:** `CHANGELOG.md`, from which release notes are built; sha256 and
  build provenance for the zip; CodeQL; `composer audit`; job timeouts;
  Dependabot cooldown; `asset/dist` built by CI and the release instead of
  committed.
- **Decided against:** `IwacInstance` constants as configuration — the aliases
  are deliberately not configuration and there is one IWAC instance; running
  the CLI through Omeka's `bootstrap.php` — it uses none of the classes that
  file patches for PHP 8.5.

Updated for 3.19.0. The July and September implementation work is complete. The [27 September 2026 review](module-review-2026-09-27.md) of 3.19.1 is implemented in 3.20.0 ([operations-3.20.md](operations-3.20.md)); its one deliberately open item is typeahead server-side caching (measure `use_cache` first). The [September closure report](module-review-2026-09.md) and [operations guide](operations-3.19.md) replace the old phased implementation plan. PHPStan is a blocking CI gate; SQL/event/key/rebuild contracts have a real Omeka/Typesense integration lane.

## Remaining measurement and operational work

- Run judged, representative queries through the benchmark harness before changing token dropping, reranking, embedding models or long-document chunking. Record latency, response size and relevance together. The disposable-fixture run only validates the harness.
- Measure source SQL with EXPLAIN and realistic catalog sizes before adding core database indexes or making further reader changes.
- Complete the [deployment checklist](deploy-checklist.md), including live UI acceptance, key rotation and queue monitoring. These checks have not been claimed complete by local tests.
- Choose a withdrawal-latency target and monitor oldest pending changes. Indexing is eventually consistent; direct SQL/event-disabled writes and the new-resource commit/post-event crash gap require reconciliation by rebuilding.
- If production JavaScript diagnostics require source maps, design private artifact storage and retention alongside the release build (the bundle is built by CI and the release since 3.27, not committed).

## Deliberate design decisions

- Entity occurrence aggregates refresh on full rebuild; incremental processing refreshes authority metadata and visibility.
- Two alias swaps are individually atomic, with guarded restoration; Typesense offers no two-alias transaction.
- Keep the two small schema definitions explicit and protected by drift tests. A generator is unwarranted while their shared fields are small and title stemming intentionally differs.
- Result-card derivations are extracted and tested. Separate list/gallery components would duplicate their largely shared layout and CSS.
- `App.svelte` owns the search state and wires components; it does not fetch. The fetch rules (snapshot adoption, histogram staleness, aborted requests, the semantic-only dead end) live in `lib/searchResults.svelte.ts` and are unit-tested against a fake client. Components are not rendered in unit tests, so a markup refactor is checked by rendering the bundle before and after against a mocked Typesense.
- Do not add an unrestricted admin search key without a concrete product requirement.
- Omeka supplies Laminas and framework interfaces. Do not add direct Laminas/PSR module dependencies to simplify standalone tools or tests.

Product and infrastructure decisions are tracked in [ROADMAP.md](../ROADMAP.md).
