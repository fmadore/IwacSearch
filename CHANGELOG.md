# Changelog

All notable changes to IwacSearch are documented here. The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project uses [Semantic Versioning](https://semver.org/). Each release's notes on GitHub are built from its section below; the deployment steps and acceptance checks for a release are in [docs/deploy-checklist.md](docs/deploy-checklist.md).

Versions that were never published (tagged and released) are folded into the next release that shipped them: 3.20.0 into 3.21.0, 3.22.0 into 3.22.1, 3.16.2 into 3.17.0, 3.0.1–3.8.x into 3.9.0, and the 0.2.6–2.0.x line into 3.0.0.

## [Unreleased]

### Fixed

- The pager's Previous/Next buttons announced a name that did not contain their visible word ("Préc.", "Suiv.", "Prev"), which fails WCAG 2.5.3 for speech-input users and axe-core 4.14's `label-content-name-mismatch`. The visible words are now "Précédente"/"Suivante" and "Previous"/"Next", each part of the button's name; below 600px the buttons still show only chevrons.

## [3.27.0] - 2026-11-01

**Upgrade:** run **Modules → IWAC Search → Upgrade** (it creates `iwac_search_generation` and `iwac_search_worker`, moves `iwac_search_rollback` into the first and drops it). No reindex: the schemas stay `iwac_v11` and `iwac_index_v5`. Do not start a rebuild across the code swap — the write gate's MySQL lock names changed. A checkout deployed by hand now needs `npm ci && npm run build`; the release zip still carries the bundle. Details: [docs/operations-3.27.md](docs/operations-3.27.md).

### Security

- The server-side first page ran a page block's hand-written locked filter with the admin key, behind `is_public:=true && …`. A filter with an unbalanced parenthesis, such as `type_s:=article) || (type_s:=document`, escaped that guard and put private documents' metadata into the page and the shared snapshot cache (never their OCR). A locked filter is now checked when the block is saved and again before every render: unbalanced parentheses or backticks, a backslash and any mention of `is_public` are refused, and a stored one that fails falls back to the client's scoped key. Picker values ending in `*`, holding a backslash or wrapped in quotes are dropped.
- Resources with an unapplied change are left out of the server-rendered first page and of newly minted search keys (which then last two minutes) until the drain applies it, so a record just made private stops showing there at once.
- Uninstall revokes every search key the module cached in Typesense and deletes all their settings slots; it used to delete only the legacy slot, leaving the live key behind.

### Added

- `GET /discovery/health` for uptime monitors: Typesense reachability and the indexing backlog (pending changes, age of the oldest), 200 or 503, no secrets.
- JSON errors carry a stable `code` and a `request_id`, also sent as `X-Request-ID` and written to the log with the cause.
- The page block can be given theme or module markup through Omeka's block templates, and Omeka's site search finds a page by its search block's title and intro.
- A generation ledger: a rebuild records every collection it builds, deletes the generations it supersedes at once (keeping the live one and one previous per alias), and removes what an interrupted rebuild left. The maintenance page shows each generation's ledger status and whether a drain worker is active.
- The admin block form, the maintenance page and the scope, facet and error labels are translated into French.

### Changed

- A rebuild far smaller than the live corpus (a content type or the entity index below half) is refused, unless **Allow a smaller corpus** (`--allow-shrink`) is ticked. A rebuild waits up to two minutes for a Typesense that is still loading, and a stopped rebuild ends as stopped with a notice instead of an error.
- Saves no longer wait for each other: the write gate is eight striped locks, all of which only the drain and the rebuild cutover take.
- The drain is dispatched through a worker lease: a change saved while a drain runs is applied in the same run instead of waiting for the scheduled drain, and a Typesense outage costs one retry every two minutes rather than one failing job per save.
- A document Typesense rejects no longer stops the drain: it is removed from search, its changes are acknowledged and its id is logged. A batch with nothing accepted still counts as a server failure and is retried.
- On page blocks with a title, and on item-set pages, the surface's headings sit one level below the page's own heading. The federated "All" tab has a hidden Results heading.
- A missing `vendor/` makes the module report search unavailable instead of taking the whole site down.
- The facet "Editor" is labelled "Book editor" ("Directeur d'ouvrage"), since Omeka's own French for "Editor" reads as publisher.
- `asset/dist` is no longer committed; CI and the release build it. Release notes come from this changelog, and each release carries a `.sha256` and a build provenance attestation (`gh attestation verify <zip> --repo fmadore/IwacSearch`).
- The theme contract is synced from IWAC-theme 2.31.0.
- Development: browser tests with Playwright and axe; the client tests are type-checked, with `noUncheckedIndexedAccess` and type-aware ESLint; PHPUnit fails on deprecations and notices; CodeQL, `composer audit` and job timeouts in CI; Dependabot waits five days; Lighthouse budgets hold `/search` and `/search/everything`; the PHP follows PSR-12 (`composer lint` in CI); PHPStan runs at level 8 against the released Omeka S 4.2.1 tree instead of hand-written stubs; PHPUnit 13.4.5.

### Fixed

- Typeahead, export and map failures showed raw English transport errors; they now show translated text, and a failed map fetch says so and offers a retry instead of "no places". A surface that fails to mount shows a notice instead of an empty box, without stopping the others on the page.
- On screens narrower than 400 px, the List/Gallery/Map toggle lost its accessible names.
- Picking a typeahead entry within 250 ms of the last keystroke left the typed prefix as a query on top of the new filter.
- A slow "did you mean" reply could reappear above the next search's results.
- Syncing the URL wiped the browser history entry's existing state.
- The "Prominent facets" help mentioned a "More filters" control the client no longer has.
- The `iwacSearchUrl()` view helper failed when called without a view; it returns the federated search URL. A Typesense import answer that is not JSON lines now fails with a clear message.

## [3.26.2] - 2026-10-27

No reindex. Install alongside IWAC-theme 2.30.1.

### Changed

- A result card's DOI and Wikidata marks are drawn in their own colours (the DOI's yellow disc, Wikidata's red, green and blue barcode) instead of muted single-colour masks. They are the same files IWAC-theme 2.30.1 draws on the item page.

## [3.26.1] - 2026-10-26

No reindex.

### Fixed

- Clicking a cluster on the entity map zooms in one step. It did nothing: the handler read the cluster's position after MapLibre had cleared it from the click event, and threw.
- Entities that share exact coordinates (Mô and Djarkpanga in Togo, for one) never split apart at any zoom. Their cluster or dot now opens one popup listing every entity there, most-mentioned first ("2 entries at this location").

## [3.26.0] - 2026-10-26

**Upgrade:** the module now requires **PHP 8.4** (the live server runs 8.5). No reindex. Deploy with IwacVisualizations 1.85.0 and IWAC-theme 2.30.0, or a visitor moving between a search map and a dashboard map downloads MapLibre twice.

### Changed

- PHP 8.4 minimum; CI tests PHP 8.4 and 8.5. `symfony/yaml` 8; development moves to PHPUnit 13.
- Maps load MapLibre GL 6.16.0, with the same files and SRI hashes as IwacVisualizations 1.85.0.
- npm and Composer dependencies upgraded within their ranges; the theme contract is synced from IWAC-theme 2.30.0.

## [3.25.0] - 2026-10-23

**Upgrade:** schemas `iwac_v11` and `iwac_index_v5`. Run a **full reindex** right after installing: until the new collections are live, clicking an editor's name filters on a field the old collection does not index, and the search errors. Ship with IWAC-theme 2.28.0, which draws the same marks on the item page.

### Added

- Result cards link the record's DOI and its Wikidata entity, each led by its scheme's mark and opening in a new tab; entity cards on the index show their Wikidata QID the same way. Documents gain `wikidata_id` (the first wikidata.org URI on `dcterms:identifier`), and `doi` is stored as the bare `10.…` DOI.
- A reference card's citation line filters: the journal or publisher, a chapter's book and each of its editors are toggles, as the byline's authors already were. An edited volume's byline lists its editors, followed by "(dir.)". `editor_ss` becomes a facet ("Editor").

## [3.24.0] - 2026-10-21

### Changed

- Every icon is drawn with Lucide, the icon library of IWAC-theme and IwacVisualizations, instead of Bootstrap Icons, so the search box under the header matches the one in it. The drawer's close button, the Sentiment group's disclosure arrows and an active-filter chip's remove mark are icons instead of text characters.

## [3.23.2] - 2026-10-21

### Fixed

- The active-filter chip lists in the summary strip and the facet panel lost their list reset in a refactor, so the theme bulleted and indented them, and the summary strip ran to two lines with "sorted by" under the chips.

## [3.23.1] - 2026-10-20

### Fixed

- The date in a result card's dateline sits on the type label's baseline; it was about 0.75px off.

## [3.23.0] - 2026-10-19

### Added

- **An item-set search surface for the theme.** The `iwacItemSetSearch($itemSet)` view helper returns the content search scoped to one item set (URL state under `bset.`, first page server-rendered), which IWAC-theme's item-set page shows in place of its ledger. It returns nothing, and the page keeps the ledger, when Typesense is down, when the index holds under 98% of what the visitor may see of the set (authority sets, sets mixing indexed and unindexed classes) and for signed-in editors, whose count includes private items.

## [3.22.1] - 2026-10-10

3.22.0 was tagged but never published: its release build wrote the zip to the wrong path (an environment variable named `ZIP`, which Info-ZIP reads as options). 3.22.1 is 3.22.0 plus that workflow fix.

**Upgrade:** schema `iwac_v10`. Run a **full reindex**; until then cards render as before.

### Added

- Card bodies written by a model (the `bibo:shortDescription` summary, or a publication's table of contents on template 21) carry the EU "AI generated" mark, from the new `abstract_ai` field. RIS exports add an `N1` note beside such a summary's `AB`.
- A French catalogue for the module's PHP strings (`language/fr.mo`): the page block's landmark name and the no-script text translate on the French site.

### Changed

- The "search everything" page uses the stack's ruled tabs instead of filled orange pills, with manual activation; paging the All tab lands on the merged list and announces it.
- An error shows "Search unavailable", a translated hint and a Retry button instead of the raw transport message, and keeps the toolbar, so the mobile Filters drawer stays reachable.
- Dates are formatted in UTC and numbers by the page's locale, with real plurals. The year slider takes its bounds from the data and clamps a URL's range to them.
- Typing writes one history entry per burst, and recent searches no longer fill with a query's own prefixes.
- Accessibility: one live region per surface, speaking about the settled response; no nested or duplicated landmarks; focus stays in place when a control removes itself or a ticked facet re-sorts its group; the typeaheads are proper comboboxes; primary-coloured text under 4.5:1 is lifted to `--primary-hover`; "Link copied" is announced, and a failed copy says so.
- The search surfaces align to the theme's content edge and title grammar; the sticky facet column clears the theme's pinned section strip; the Subjects and Events entity types take the theme's new colours.
- Releases are gated on the whole CI suite, and the version and `npm audit` guards are ported from IWAC-theme. The MapLibre pins are checked weekly against IwacVisualizations.

### Fixed

- Two request races: a shared typeahead abort slot, and a search signal that arrived late.
- Concurrent first requests could each mint a search parent key, orphaning one in Typesense; the mint now runs under a database lock. A Typesense outage no longer costs every page view the SSR timeouts (a 20-second "unavailable" flag), and key expiry is rebased onto the visitor's clock.
- Enter on the search box's clear button or on "Clear history" ran the typeahead's highlighted row instead of pressing the button.
- A crafted numeric facet value (`0x10`, `1e3`) reached Typesense and error-stated the whole surface.

## [3.21.1] - 2026-10-06

Dependencies only: no schema change, no reindex, no module upgrade step. Deploy together with IwacVisualizations 1.75.2. Coming from 3.19.1 or earlier, follow 3.21.0's steps first.

### Changed

- The Typesense transport moves to Guzzle 8; `php-http/guzzle7-adapter` and `http-interop/http-factory-guzzle`, unused, are removed.
- The Map view loads MapLibre GL 6.12.0, with the same files and SRI hashes as IwacVisualizations.
- The bundle is rebuilt with Svelte 5.57.1 and Vite 8.3.3; the theme contract is synced to IWAC-theme 2.23.0.

## [3.21.0] - 2026-10-05

Also ships everything prepared as 3.20.0, which was never tagged.

**Upgrade:** publish **IWAC-docker v1.1.0** first (its `search-worker` runs the scheduled drain), run **Modules → IWAC Search → Upgrade**, then a **full reindex** to schema `iwac_v9`. Where a Content-Security-Policy is enforced, `worker-src` must allow `blob:` for MapLibre 6. See [docs/operations-3.20.md](docs/operations-3.20.md) and the deploy checklist.

### Added

- Gemma 4 31B (`gemma_4_31b_it_*`) and Qwen3.8 27B (`qwen3_8_27b_*`) sentiment fields are indexed and facetable, beside GPT-5.6 Luna, Mistral Small 2603 and DeepSeek V4 Flash 0731. The sidebar still surfaces the GPT-5.6 Luna trio only.

### Changed

- The rebuild cutover runs in phases: the corpus-sized work runs before the write gate, which covers only the late replay, one count check and promotion. The job log reports `gate_held_seconds`.
- Builds that are never promoted are dropped instead of staying in Typesense memory; journal and drain-job rows are pruned after seven days; saves no longer queue a drain while one is pending; deletes and aggregate reads are batched.
- Public keys embed the multi-search limit, and the IWAC-docker proxy serves only the search endpoints.
- MapLibre 6.11.2 is pinned to the exact files and SRI hashes IwacVisualizations uses. The header typeahead bundle shrinks from 8.8 to 5.9 KB gzipped.
- In dark mode, labels on a primary fill use the theme's `--ink-on-primary` (6.10:1) instead of white (3.23:1); the federated tabs are no longer dimmed with opacity.

### Fixed

- A write that threw inside a job held the mutation gate for the rest of the process, so other saves waited five minutes and failed.

## [3.19.1] - 2026-09-12

### Fixed

- The maintenance dashboard no longer crashes when the 3.19 database migration has not run, or only one module table exists. It shows recovery instructions and blocks job dispatch until the schema is ready. `php cli/repair-schema.php` creates only the missing tables, for an install Omeka already marks as upgraded.

## [3.19.0] - 2026-09-12

**Upgrade:** install the release zip, run the module upgrade, configure an alias-only search parent key, run a **full reindex**, revoke older parent keys, purge external caches of the bootstrap JSON and schedule `php cli/maintenance.php drain` every minute. See [docs/operations-3.19.md](docs/operations-3.19.md).

### Added

- Durable background indexing: a change journal written at Omeka's API events, drained by background jobs with a scheduled recovery drain.
- Guarded rebuilds: edits and deletions made during a build are replayed before promotion, source and index counts are reconciled, aliases are restored on failure, and previous generations are retained for controlled cleanup.
- Real Omeka 4.2.1 + Typesense 30.2 integration tests in CI.

### Changed

- Public metadata is projected for privacy: public search keys are alias-only, and restricted OCR is served only as bounded excerpts.
- Search, facets, counts, maps and exports share one exact-query behaviour. Requests have bounded timeouts and a single authorisation refresh, SSR caching tracks index changes, and supporting requests no longer inflate search analytics.
- A locked PHP dependency tree; Vitest 5.

## [3.18.0] - 2026-08-31

### Fixed

- The pre-3.0 filter URLs (`facet`, `sort_by`, `sort_order`, `resource_property`) answer 301 to `/search`, carrying `q` across, instead of a 200 per permutation that Google had indexed 1,306 times.

## [3.17.0] - 2026-08-26

**Upgrade:** schemas `iwac_v8` and `iwac_index_v4`; full reindex.

### Fixed

- An entity's mention count includes author, editor and publisher links again, as the Hugging Face figure always has; about 3,045 records showed a false 0. The card adds the authored share ("8 mentions · 8 as author"), and the entity index gains a Most authored sort. Incremental saves no longer drop authorship.

## [3.16.1] - 2026-08-25

### Fixed

- The typeahead panel no longer closes when the debounced search commits, so pausing to read a suggestion keeps it open.

## [3.16.0] - 2026-08-25

### Changed

- The typeahead no longer covers the results or eats clicks; page size (10/20/50) and a jump-to-page box; 24px targets; ruled active states; semantic and dark-map fixes.

## [3.15.0] - 2026-08-24

### Changed

- Accessibility: the mobile filter drawer is a full modal (focus trap, Escape, focus restore); visible focus indicators; a skip link and named results region; one announced result count; responsive gallery images.

## [3.14.0] - 2026-08-24

### Fixed

- Result, thumbnail, typeahead, map and export links stay on the site being browsed; the English site handed readers to the French one.
- A query that matches nothing says so, with "did you mean" chips and an opt-in to semantically related items, instead of presenting the vector leg's fixed top-k as results.

## [3.13.1] - 2026-08-14

### Changed

- The theme-token guard covers `asset/css/iwac-search.css` and every non-colour token; 113 fallbacks are corrected. A fallback chain passes when it resolves to the outer token's value. Supersedes 3.13.0.

## [3.13.0] - 2026-08-14

### Changed

- The theme-token guard walks every stylesheet the module ships, not only `src/`. Superseded by 3.13.1, which allows legitimate fallback chains.

## [3.12.0] - 2026-08-13

**Upgrade:** schema `iwac_v7`; full reindex.

### Added

- Audiovisual records gain their own channel, media kind, platform, duration, rights and watch-URL fields, an Audiovisual scope, and transcripts indexed as full text. A YouTube channel is no longer filed as a newspaper.

## [3.11.0] - 2026-08-07

**Upgrade:** schema `iwac_v6`; full reindex.

### Changed

- Sentiment is read from the three generation-2 annotators (GPT-5.6 Luna, Mistral Small 2603, DeepSeek V4 Flash 0731); GPT-5.6 Luna is the surfaced trio.

## [3.10.0] - 2026-08-06

### Added

- A page block can lock several types, countries, newspapers and languages, through a checkbox picker per field.

## [3.9.1] - 2026-08-06

### Fixed

- The ledger numerals no longer ask Besley for tabular figures it does not have.

## [3.9.0] - 2026-08-05

The first release since 3.0.0; it carries 3.0.1–3.8.x, which were not published separately. The schemas move to `iwac_v5` and `iwac_index_v3`; a full reindex covers every step.

### Added

- Ledger-row results with type badges, a list/gallery view, a summary strip and a loading skeleton.
- Accent-insensitive search, exact-phrase and excluded terms, a year histogram, result export, and the photographs subset.
- Arabic-transliteration synonyms, search analytics, a merged All tab on `/search/everything`, and a Map view on the entity index.
- Full incremental sync: batch operations, media edits and item-set deletion re-map in the same request.
- Publication tables of contents are searchable.
- While the module is active, Omeka's public advanced-search URLs redirect to the federated search.
- An installable module zip on every release; LICENSE, CITATION.cff and SECURITY.md.

## [3.0.0] - 2026-06-09

Carries the unpublished 0.2.6–2.0.x line.

### Added

- Clickable result-card filters; references show their publication type; per-block URL state; any facet value is searchable; results page through every match.
- The indexer reads Omeka's MySQL database directly instead of the Hugging Face dataset; Typesense 30.2.

### Removed

- The curated browse-config system: scopes are configured per page block, and `/search/everything` federates content and entities.

## [0.2.5] - 2026-04-27

### Fixed

- A per-search Typesense error inside a 200 `multi_search` response no longer crashes the page.
