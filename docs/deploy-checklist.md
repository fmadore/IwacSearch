# Deployment and acceptance checklist — 3.19.0

## 3.21.0 additions

No schema change and no reindex.

- [ ] **Content-Security-Policy, if one is enforced:** the Map view now loads MapLibre 6, which is ES-module only and boots its worker from a `blob:` URL — `worker-src` must allow `blob:` (and `script-src` must allow `https://cdn.jsdelivr.net`, as before). IwacVisualizations has needed the same since its MapLibre 6 move, so a site serving both is already configured.
- [ ] Open the Map view on the entity index (e.g. `/s/westafrica/browse/…` with the Map toggle) and confirm clusters render; in devtools, `maplibregl.getVersion()` reads `6.11.2`, and the three jsDelivr requests carry `integrity`.
- [ ] Confirm the header typeahead still suggests on a non-search page (its bundle was slimmed from ~25 KB to ~16 KB).
- [ ] On `/search`, walk the result states once — a query, a zero-result misspelling (the "Did you mean" chips), a query that only matches semantically (the opt-in and its banner), and the Filters drawer on a phone. `App.svelte` was split into a fetch module and three components; a mocked-backend render of these states was pixel-identical before and after, but it has not been seen against the live index.

## 3.20.0 additions

Follow [operations-3.20.md](operations-3.20.md) after (or together with) the 3.19 rollout below.

- [ ] Run the Omeka module upgrade (purifies and flags existing search blocks' intro text).
- [ ] Publish IWAC-docker's v1.1.0 release (its php image also runs `search-worker`), then deploy IWAC-docker with its `docs/deploy-hardening.md`: recreate `typesense` at a quiet time, start `search-worker`, recreate `web`. This satisfies the 3.19 "schedule the drain" item.
- [ ] Confirm `/search-api/multi_search` still serves the search page and header typeahead, and that `/search-api/keys` or `/search-api/collections` answer 403.
- [ ] On the first rebuild, read `gate_held_seconds` and `catch_up` in the job log, and check the maintenance page's generation list.
- [ ] On staging, save an item with a missing required value inside a long-running job, then save another item from the admin: the second save must not wait for the job to finish.

These are live-environment checks, still pending. Follow the ordered migration in [operations-3.19.md](operations-3.19.md); publishing a GitHub release does not deploy the module.

## Required rollout

- [ ] Install the release zip (includes production dependencies) or install source with Composer from its lockfile.
- [ ] Upgrade the Omeka module to create the journal and rollback tables before resuming catalog writes.
- [ ] Configure an alias-only search parent; replace any mounted wider key.
- [ ] Run a full reindex and verify source/server counts, successful replay and both live aliases. This also applies all earlier schema migrations. Keep search in maintenance during the first privacy migration if the outgoing index contains values that must no longer be exposed.
- [ ] Revoke old parent keys and purge external caches containing old bootstrap JSON.
- [ ] Schedule the maintenance drain command every minute and verify the Omeka background worker.
- [ ] Check pending count/oldest timestamp, logs and storage capacity for retained generations. Schedule explicit pruning as appropriate.

## Acceptance on staging, then the live site

- [ ] Verify public metadata, linked authority visibility, thumbnails and bounded restricted-OCR excerpts with controlled fixtures. Inspect both SSR and browser responses; public keys must deny retained concrete collections and full OCR/vector projection.
- [ ] Edit, privatize and delete fixture items/authorities; move media and change item-set membership. Confirm queued work drains and content/entity search updates. Do not assume a fixed five-second withdrawal guarantee.
- [ ] On staging, interrupt Typesense, save a fixture change, restore the service and retry draining. Confirm work remains pending until successfully applied.
- [ ] On staging, edit/delete fixtures during a rebuild and inject a failing build. Confirm reconciliation and retention of the previous working aliases.
- [ ] Exercise content/entity/All search, exact and excluded terms, facets, year histogram/retry, map, export and pagination. Counts and scope must agree; histogram intentionally ignores its own selected year interval.
- [ ] Verify matching SSR hydration, sorted/page share links, browser Back, FR/EN labels, header suggestions and keyboard selection.
- [ ] Check list/gallery cards, entity mention/authorship counts, mobile filters, focus, Escape dismissal and scroll restoration.
- [ ] If analytics is enabled, provision rules and verify real searches are counted while auxiliary traffic is excluded. Native no-hit analytics and the browser semantic-only outcome have different meanings.

Optional infrastructure/product decisions remain in [ROADMAP.md](../ROADMAP.md); tuning remains in [engineering-roadmap.md](engineering-roadmap.md).
