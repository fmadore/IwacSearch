<script lang="ts">
  import type { AdminBootstrap, BrowseConfig } from './lib/types';
  import { AdminStore } from './lib/store.svelte';
  import ConfigTable from './components/ConfigTable.svelte';
  import ConfigFormDrawer from './components/ConfigFormDrawer.svelte';
  import Button from '../svelte-shared/components/Button.svelte';

  /**
   * Root of the admin CRUD app.
   *
   * State is owned by AdminStore (Svelte 5 runes on class fields), so
   * child components read `store.configs` / `store.lastError` directly
   * and re-render with fine-grained reactivity on each field update.
   *
   * Flow:
   *   - configs list is pre-inlined by the PHP shell → no fetch on mount
   *   - "New" opens the drawer in create mode
   *   - Edit row → drawer in edit mode, pre-filled
   *   - All mutations are optimistic; rollback on server error
   */
  interface Props {
    bootstrap: AdminBootstrap;
  }

  const { bootstrap }: Props = $props();

  // svelte-ignore state_referenced_locally
  // The store captures `bootstrap` once at mount — by design. It's
  // emitted by the server, never changes post-mount, and the store
  // maintains its own reactive state from that initial seed.
  const store = new AdminStore(bootstrap);

  let drawerOpen = $state(false);
  let editTarget = $state<BrowseConfig | null>(null);

  function openCreate(): void {
    editTarget = null;
    drawerOpen = true;
  }

  function openEdit(config: BrowseConfig): void {
    editTarget = config;
    drawerOpen = true;
  }

  function closeDrawer(): void {
    drawerOpen = false;
    editTarget = null;
  }

  async function handleSave(draft: Omit<BrowseConfig, 'id'>): Promise<void> {
    const result = editTarget
      ? await store.update(editTarget.id!, draft)
      : await store.create(draft);

    if (result !== null) {
      // Success — close the drawer. Errors stay visible in the banner
      // below the page header so the user sees what went wrong without
      // losing their form entries.
      closeDrawer();
    }
  }

  async function handleDelete(config: BrowseConfig): Promise<void> {
    if (config.id === null || config.id < 0) return;
    await store.delete(config.id);
  }

  // Sort the list by position for display. Stored configs have integer
  // positions; optimistic inserts get a position from the form and slot
  // in accordingly.
  const sortedConfigs = $derived(
    [...store.configs].sort((a, b) => a.position - b.position || a.title.localeCompare(b.title)),
  );
</script>

<div class="iwac-admin">
  <header class="iwac-admin__actions">
    <div class="iwac-admin__count">
      {store.configs.length}
      {store.configs.length === 1 ? 'browse page' : 'browse pages'}
    </div>
    <Button variant="primary" onclick={openCreate}>+ New browse page</Button>
  </header>

  {#if store.lastError}
    <div class="iwac-admin__error" role="alert">
      <strong>Something went wrong.</strong>
      <span>{store.lastError}</span>
      <button
        type="button"
        class="iwac-admin__error-dismiss"
        aria-label="Dismiss error"
        onclick={() => store.dismissError()}
      >
        ×
      </button>
    </div>
  {/if}

  <ConfigTable
    configs={sortedConfigs}
    onEdit={openEdit}
    onDelete={handleDelete}
    inFlight={store.mutationInProgress}
  />

  <ConfigFormDrawer
    open={drawerOpen}
    config={editTarget}
    catalog={store.catalog}
    inFlight={store.mutationInProgress}
    onSave={handleSave}
    onClose={closeDrawer}
  />
</div>

<style>
  .iwac-admin {
    display: flex;
    flex-direction: column;
    gap: var(--space-md, 1rem);
    color: var(--ink, #222);
    font-size: var(--text-base, 1rem);
  }
  .iwac-admin__actions {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: var(--space-md, 1rem);
  }
  .iwac-admin__count {
    color: var(--muted, #666);
    font-size: var(--text-sm, 0.9rem);
    font-variant-numeric: tabular-nums;
  }
  .iwac-admin__error {
    display: flex;
    align-items: center;
    gap: var(--space-sm, 0.5rem);
    padding: var(--space-sm, 0.5rem) var(--space-md, 1rem);
    background: color-mix(in srgb, var(--primary, #c66) 10%, var(--surface, #fff));
    border: 1px solid color-mix(in srgb, var(--primary, #c66) 35%, transparent);
    border-radius: var(--radius-md, 0.75rem);
    color: var(--ink-strong, var(--ink, #222));
    font-size: var(--text-sm, 0.9rem);
  }
  .iwac-admin__error-dismiss {
    margin-inline-start: auto;
    border: none;
    background: none;
    color: var(--muted, #666);
    cursor: pointer;
    width: 1.5rem;
    height: 1.5rem;
    border-radius: var(--radius-full, 9999px);
    font-size: var(--text-lg, 1.125rem);
    line-height: 1;
    display: inline-flex;
    align-items: center;
    justify-content: center;
  }
  .iwac-admin__error-dismiss:hover {
    background: var(--surface-sunken, #f0f0f0);
    color: var(--ink, #222);
  }
</style>
