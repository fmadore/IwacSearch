# IwacSearch 3.27 operations: indexing pipeline

What the 3.27 indexing work changes for whoever runs the module. It changes no Typesense schema (`iwac_v11` / `iwac_index_v5` stay) and needs **no reindex**. The [3.20 guide](operations-3.20.md) and the [3.19 guide](operations-3.19.md) remain the reference for everything not listed here.

## Deployment

1. Install the release and run **Modules → IWAC Search → Upgrade**. The upgrade (connection only, as Omeka runs it while the module is inactive) creates two tables and migrates one:
   - `iwac_search_generation`, the generation ledger. The rows of the old `iwac_search_rollback` table (the outgoing alias targets of the last rebuild) become its `previous` rows, and `iwac_search_rollback` is dropped.
   - `iwac_search_worker`, the drain worker's lease row.

   `php cli/repair-schema.php` does the same for an install that missed the upgrade; the maintenance page refuses to dispatch anything until all three module tables exist.
2. **Do not start a rebuild across the code swap.** The write gate moved from one MySQL lock (`…:mutation`) to eight (`…:mutation:0` … `:7`). While old and new PHP processes overlap, an old cutover does not exclude new saves and the reverse. A drain is self-correcting (every save journals again after it commits), but a cutover in that window could miss a save until the item's next change. Let in-flight rebuild jobs finish first, or stop them.
3. Nothing in IWAC-docker has to change. `search-worker`'s per-minute `cli/maintenance.php drain` keeps working (see _Drain_ for what it now does).

## Generation ledger and retention

Every build is recorded in `iwac_search_generation` **before** its collection is created (`building`); a promotion records the new generation as `live` and the one it replaced as `previous` (the rollback target), and retires every other generation of that alias.

- **A rebuild deletes the generations it retires, at once.** Each alias keeps exactly its live generation and one previous one. Until 3.26 nothing was deleted until someone ran the prune, and the prune kept anything younger than seven days. After the first rebuild on 3.27, Typesense memory should settle at two generations per alias.
- **A rebuild drops what an interrupted one left.** Holding the rebuild lock, it drops every `building` row's collection before it starts (a build an alias still serves, after a failed rollback, is recorded as `live` instead). Job stats: `generations.recovered`, `generations.removed`.
- **Nothing an alias targets is ever deleted**, whatever the ledger says; when the alias table cannot be read, nothing is deleted. A failed drop keeps its row, and the next rebuild or prune retries it. If the ledger cannot be written after a promotion, the rebuild still succeeds (the aliases are verified and live) and logs an error.
- **The prune** (`cli/maintenance.php prune`, the cleanup button, IWAC-docker's daily run) runs the same two ledger passes, then the old rule for collections the ledger never recorded — builds from before 3.27: not aliased, older than seven days, and the newest of them is kept only while the ledger has no rollback target for their alias. On an upgraded install, expect the first prune after the first 3.27 rebuild to remove the old leftovers.
- The maintenance page's generation list has a **Ledger** column: live, previous, retired, building (or interrupted), or "not recorded" for pre-3.27 builds.
- **Uninstall** drops the module's tables and leaves every Typesense collection in place, as before; it now logs (Omeka log, notice) the names of the collections the ledger recorded, so they can be removed by hand.

## Rebuild guards

- **Shrink guard.** Before promotion, the build's per-type counts (`type_s`) and its entity total are compared with the live generations; a count below **half** refuses the swap with a message naming each type (`photograph: 600 → 0`). The previous generations stay live and the build is dropped. A first install (no live alias) is never refused; a live collection that cannot be faceted by `type_s` is compared by its total. When a large drop is intended — a subset removed on purpose — rebuild with:
  - `php cli/reindex.php --allow-shrink`, or
  - the **Allow a smaller corpus** checkbox under _Run full reindex_ on the maintenance page (job argument `allow_shrink`).

  Job stats carry `allow_shrink`. The live counts are read before the write gate is taken, so `gate_held_seconds` does not grow.
- **Readiness wait.** A rebuild first polls Typesense's `/health` for up to two minutes (every three seconds, and a job stop ends the wait), then proceeds whatever the answer. Starting a rebuild right after a Typesense restart no longer fails on "Not Ready or Lagging".
- **Stopping a rebuild** (Omeka's _Stop_ on the job) is logged as a **notice** and the job ends as _stopped_, not _error_. The build is dropped; the live aliases are untouched.
- Every check that refuses promotion now throws one exception type (`Indexer\Exception\VerificationException`); a per-type count mismatch reports what the catalog and the index each hold.

## Write gate

Saves of items, media and item sets no longer queue behind one another. A save takes **any one of eight** named MySQL locks; the drain snapshot and the rebuild's watermarks and cutover take all eight, in order, so they still exclude every save exactly as before.

- Concurrent editors' saves, and a long batch write, no longer make other saves wait. A save waits only while a drain snapshot (milliseconds) or the rebuild cutover holds the gate, or when eight saves are in flight at once.
- The cutover may now wait for up to one in-flight save per stripe before it holds the gate (each save is short). `gate_held_seconds` still measures the time saves may have waited; the 300-second limits are unchanged, and a save that waits that long still fails loudly rather than proceed unjournaled.
- MariaDB ≥ 10.0.2 and MySQL ≥ 5.7 allow several named locks per session, which this relies on.

## Drain

A save starts a `DrainChanges` worker only when it wins the lease row `iwac_search_worker`; Omeka's job table is no longer consulted.

- The worker loops until the journal is empty **and** no save asked for work since its pass began, so a change saved while a pass ran is applied by that worker, not left for the next minute's scheduled drain (the lost wake-up of 3.26).
- After five minutes with work left, the worker dispatches a successor job itself.
- **After a failure** (Typesense down, say) the lease is held for one two-minute window: one retry per window instead of one failing job per save. **Retry pending changes** on the maintenance page still dispatches a worker at once. The page now shows whether a worker is active.
- A worker that dies stops beating; after two minutes the next save, or the scheduled drain, takes over.
- `cli/maintenance.php drain` works only when it can claim the lease; otherwise it logs "A drain worker holds the lease" and exits. Several minutes of that line while the queue grows means a worker is alive but stuck — check the job log.
- Fewer `DrainChanges` rows accumulate in Omeka's job table (one per worker, not one per save while none was running); retention still prunes completed ones after seven days.

## Pending changes hidden from public search

Between a save and its drain, the indexed copy of an item may predate a withdrawal or a privatisation. While the journal lists such items:

- the **SSR** first page excludes them (`id:!=[…]`); with more than 250 pending, or a journal it cannot read, the SSR is skipped and the browser fetches the first page itself;
- a **freshly minted scoped key** excludes the newest 250 of them and lives **120 seconds** instead of an hour. With an empty journal the key is exactly what it was.

Operational consequences:

- While a backlog persists (a large batch edit, a Typesense outage), the edited items are missing from public results until drained — fail closed — and each open browser tab renews its key about once a minute (the client renews 60 s before expiry). Check that IWAC-docker's rate limit on `/discovery/token` allows that.
- Keys minted before a save keep showing the old copy until the drain applies it, as before; the window is the drain's latency (seconds).
- Beyond 250 pending items, only the newest 250 are hidden from keys. Watch the oldest pending change on the maintenance page: a backlog that large is an incident in itself.

## Verification

Run for this work: PHPUnit (unit tests for the ledger cleanup, the shrink guard, the readiness wait, the striped gate modelled as several processes, the pending-change exclusion in the SSR and in the scoped key), PHPStan level 6, `npm run lint`, and the real Omeka 4.2.1 / MariaDB / Typesense 30.2 integration lane (`tests/integration/rebuild.php`), several times and in parallel. The lane now covers: the rollback-record migration; orphan recovery and retention at promotion; the prune's ledger and legacy passes; uninstall's log; a stopped rebuild job (notice, build dropped); the shrink refusal and the job's override; the lease handshake (single dispatch, request during a pass, back-off, stale takeover, failed dispatch, successor); two real saves in flight at once while the drain yields and the cutover excludes both; and a real scoped key hiding a pending item.
