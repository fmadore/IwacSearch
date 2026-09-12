# Deployment and acceptance checklist — 3.19.0

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
