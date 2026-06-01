<script lang="ts">
  import type { BrowseConfig } from '../lib/types';
  import Button from '../../svelte-shared/components/Button.svelte';

  /**
   * Table of browse configs with inline actions.
   *
   * Delete uses inline confirmation (click → "Really?" → confirm)
   * rather than a modal — one hover, two clicks, zero popups.
   *
   * Columns are tuned for fast scanning:
   *   #     — position + short slug, for eyeballing the order
   *   Title — the public display name (longest column, flex-grows)
   *   Lock  — locked_filters rendered as one-line code with truncation
   *   F     — prominent facet count (just the number, detail lives in edit)
   *   Actions — edit, view public, delete
   */
  interface Props {
    configs: BrowseConfig[];
    onEdit: (config: BrowseConfig) => void;
    onDelete: (config: BrowseConfig) => Promise<void>;
    inFlight: boolean;
    /** Prepended to /browse/{slug} to build the "view public" link. */
    basePath?: string;
  }

  const { configs, onEdit, onDelete, inFlight, basePath = '' }: Props = $props();

  // Inline-confirm state: which row is currently asking "really?"
  let confirmingId: number | null = $state(null);

  async function handleDelete(config: BrowseConfig): Promise<void> {
    if (confirmingId !== config.id) {
      confirmingId = config.id;
      return;
    }
    await onDelete(config);
    confirmingId = null;
  }

  function cancelConfirm(): void {
    confirmingId = null;
  }

  function publicUrl(slug: string): string {
    return `${basePath}/browse/${encodeURIComponent(slug)}`;
  }
</script>

{#if configs.length === 0}
  <div class="iwac-empty">
    <p>No curated browse pages yet.</p>
    <p class="iwac-empty__hint">
      Click <strong>New browse page</strong> above to create one, or reinstall the module to re-seed the
      six default country pages.
    </p>
  </div>
{:else}
  <table class="iwac-table">
    <thead>
      <tr>
        <th class="iwac-table__col-pos" scope="col">#</th>
        <th scope="col">Title</th>
        <th scope="col">Locked filter</th>
        <th class="iwac-table__col-facets" scope="col" title="Prominent facets">F</th>
        <th class="iwac-table__col-actions" scope="col">Actions</th>
      </tr>
    </thead>
    <tbody>
      {#each configs as config (config.id ?? config.slug)}
        {@const isOptimistic = (config.id ?? 0) < 0}
        <tr class:iwac-table__row--optimistic={isOptimistic}>
          <td class="iwac-table__col-pos">
            <span class="iwac-table__position">{config.position}</span>
          </td>
          <td>
            <div class="iwac-table__title">{config.title}</div>
            <code class="iwac-table__slug">/browse/{config.slug}</code>
          </td>
          <td class="iwac-table__lock">
            {#if config.locked_filters}
              <code title={config.locked_filters}>{config.locked_filters}</code>
            {:else}
              <span class="iwac-table__muted">—</span>
            {/if}
          </td>
          <td class="iwac-table__col-facets">
            {config.prominent_facets.length}
          </td>
          <td class="iwac-table__col-actions">
            {#if confirmingId === config.id}
              <div class="iwac-table__confirm" role="group" aria-label="Confirm delete">
                <span class="iwac-table__confirm-label">Delete {config.title}?</span>
                <Button
                  variant="danger"
                  size="sm"
                  onclick={() => handleDelete(config)}
                  disabled={inFlight || isOptimistic}
                >
                  Confirm
                </Button>
                <Button variant="ghost" size="sm" onclick={cancelConfirm}>Cancel</Button>
              </div>
            {:else}
              <div class="iwac-table__actions">
                <Button
                  variant="ghost"
                  size="sm"
                  onclick={() => onEdit(config)}
                  disabled={isOptimistic}
                >
                  Edit
                </Button>
                <Button variant="ghost" size="sm" href={publicUrl(config.slug)} target="_blank">
                  View
                </Button>
                <Button
                  variant="danger"
                  size="sm"
                  onclick={() => handleDelete(config)}
                  disabled={inFlight || isOptimistic}
                  ariaLabel={`Delete ${config.title}`}
                >
                  Delete
                </Button>
              </div>
            {/if}
          </td>
        </tr>
      {/each}
    </tbody>
  </table>
{/if}

<style>
  .iwac-table {
    width: 100%;
    /*
     * Omeka S admin's global table CSS forces `table-layout: fixed` on tables
     * inside #content. Under fixed layout our `width: 1%` shrink-to-fit Actions
     * column became a literal ~10px (1% of the table) — collapsing the row
     * buttons — while Title/Locked split the slack evenly. Force auto layout
     * back so the declared column widths behave as intended. !important is the
     * established pattern for defending module styles against Omeka admin CSS.
     */
    table-layout: auto !important;
    border-collapse: collapse;
    background: var(--surface, #fff);
    border-radius: var(--radius-md, 0.75rem);
    overflow: hidden;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05);
  }
  .iwac-table thead {
    background: var(--surface-sunken, #f5f5f5);
  }
  .iwac-table th,
  .iwac-table td {
    padding: var(--space-sm, 0.5rem) var(--space-md, 1rem);
    text-align: start;
    font-size: var(--text-sm, 0.9rem);
    vertical-align: middle;
  }
  .iwac-table th {
    font-weight: 600;
    color: var(--ink, #222);
    border-bottom: 1px solid var(--border, #ccc);
  }
  .iwac-table tbody tr {
    border-bottom: 1px solid var(--border-light, #eee);
    transition: background 120ms ease;
  }
  .iwac-table tbody tr:hover {
    background: color-mix(in srgb, var(--primary, #c66) 2%, var(--surface, #fff));
  }
  .iwac-table tbody tr:last-child {
    border-bottom: none;
  }
  .iwac-table__row--optimistic {
    opacity: 0.6;
    animation: iwac-pulse 1s ease-in-out infinite;
  }
  @keyframes iwac-pulse {
    0%,
    100% {
      opacity: 0.6;
    }
    50% {
      opacity: 0.85;
    }
  }
  /*
   * !important on the column widths too: Omeka admin's #content-scoped cell
   * rules out-specify these Svelte-scoped classes, so without it the narrow
   * fixed columns (#, F) and the shrink-to-fit Actions column don't hold their
   * intended widths even once table-layout is restored above.
   */
  .iwac-table__col-pos {
    width: 3rem !important;
    text-align: center;
  }
  .iwac-table__col-facets {
    width: 3rem !important;
    text-align: center;
    font-variant-numeric: tabular-nums;
  }
  .iwac-table__col-actions {
    width: 1% !important; /* shrink-to-fit */
    white-space: nowrap !important;
  }
  .iwac-table__position {
    display: inline-block;
    min-width: 1.5rem;
    padding: 0.125rem 0.5rem;
    background: var(--surface-sunken, #f0f0f0);
    border-radius: var(--radius-sm, 0.375rem);
    color: var(--muted, #666);
    font-variant-numeric: tabular-nums;
  }
  .iwac-table__title {
    font-weight: 500;
    color: var(--ink, #222);
  }
  .iwac-table__slug {
    font-family: var(--font-mono, ui-monospace, monospace);
    font-size: var(--text-xs, 0.75rem);
    color: var(--muted, #666);
  }
  .iwac-table__lock code {
    display: inline-block;
    max-width: 18rem;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
    font-family: var(--font-mono, ui-monospace, monospace);
    font-size: var(--text-xs, 0.75rem);
    background: var(--surface-sunken, #f5f5f5);
    padding: 0.125em 0.375em;
    border-radius: 0.25em;
    vertical-align: middle;
  }
  .iwac-table__muted {
    color: var(--muted, #666);
  }
  .iwac-table__actions,
  .iwac-table__confirm {
    display: flex;
    gap: var(--space-xs, 0.25rem);
    align-items: center;
  }
  .iwac-table__confirm-label {
    font-size: var(--text-xs, 0.75rem);
    color: var(--primary, #c66);
    font-weight: 500;
    margin-inline-end: var(--space-xs, 0.25rem);
  }

  /* `.iwac-btn--sm` and the rest of the button chrome live in
     src/svelte-shared/components/Button.svelte. */

  .iwac-empty {
    padding: var(--space-2xl, 3rem) var(--space-lg, 1.5rem);
    text-align: center;
    background: var(--surface, #fff);
    border: 1px dashed var(--border, #ccc);
    border-radius: var(--radius-md, 0.75rem);
    color: var(--muted, #666);
  }
  .iwac-empty p {
    margin: 0.5rem 0;
  }
  .iwac-empty__hint {
    font-size: var(--text-sm, 0.9rem);
  }
</style>
