<script lang="ts">
  import type { BrowseConfig, FacetOption, SortOption } from '../lib/types';
  import FacetPicker from './FacetPicker.svelte';

  /**
   * Slide-in drawer for creating or editing one browse config.
   *
   * Create mode: `config` is null; on submit we call `onSave` with a
   * freshly-built draft (id: null).
   * Edit mode: `config` is the current row; edits are local to the
   * drawer until the user clicks Save.
   *
   * The drawer never renders when closed — we keep its state trivial
   * (no animation-vs-mount race). Svelte's `transition:` handles the
   * slide-in when the wrapper is re-created.
   */
  interface Props {
    open: boolean;
    config: BrowseConfig | null; // null = create mode
    catalog: { facets: FacetOption[]; sorts: SortOption[] };
    inFlight: boolean;
    onSave: (draft: Omit<BrowseConfig, 'id'>) => Promise<void>;
    onClose: () => void;
  }

  const { open, config, catalog, inFlight, onSave, onClose }: Props = $props();

  // Local form state. Re-initialised whenever the drawer is opened
  // for a different config — the $effect watches `config?.id`.
  let slug = $state('');
  let title = $state('');
  let introHtml = $state('');
  let lockedFilters = $state('');
  let prominentFacets = $state<string[]>([]);
  let defaultSort = $state('date:desc');
  let resultsPerPage = $state(10);
  let position = $state(0);
  let validationError = $state<string | null>(null);

  $effect(() => {
    // Re-seed from `config` when it changes. Reads `config` reactively
    // so closing + reopening with a different row refreshes the form.
    const c = config;
    slug = c?.slug ?? '';
    title = c?.title ?? '';
    introHtml = c?.intro_html ?? '';
    lockedFilters = c?.locked_filters ?? '';
    prominentFacets = c ? [...c.prominent_facets] : [];
    defaultSort = c?.default_sort ?? 'date:desc';
    resultsPerPage = c?.results_per_page ?? 10;
    position = c?.position ?? 0;
    validationError = null;
  });

  const isCreate = $derived(config === null);
  const slugPattern = /^[a-z][a-z0-9_-]{0,79}$/;

  async function handleSubmit(e: SubmitEvent): Promise<void> {
    e.preventDefault();
    validationError = null;

    const normalizedSlug = slug.trim().toLowerCase();
    if (!slugPattern.test(normalizedSlug)) {
      validationError =
        'Slug must start with a lowercase letter and contain only lowercase letters, digits, hyphens, or underscores.';
      return;
    }
    if (!title.trim()) {
      validationError = 'Title is required.';
      return;
    }
    if (resultsPerPage < 1 || resultsPerPage > 50) {
      validationError = 'Results per page must be between 1 and 50.';
      return;
    }

    await onSave({
      slug: normalizedSlug,
      title: title.trim(),
      intro_html: introHtml,
      locked_filters: lockedFilters,
      prominent_facets: prominentFacets,
      default_sort: defaultSort,
      results_per_page: resultsPerPage,
      position,
    });
  }

  function handleKeydown(e: KeyboardEvent): void {
    if (e.key === 'Escape' && open) {
      e.preventDefault();
      onClose();
    }
  }
</script>

<svelte:window onkeydown={handleKeydown} />

{#if open}
  <!-- Backdrop click closes. -->
  <div
    class="iwac-drawer-backdrop"
    role="presentation"
    onclick={onClose}
    onkeydown={(e) => e.key === 'Enter' && onClose()}
  ></div>

  <div class="iwac-drawer" role="dialog" aria-labelledby="iwac-drawer-title" aria-modal="true">
    <header class="iwac-drawer__header">
      <h2 id="iwac-drawer-title" class="iwac-drawer__title">
        {isCreate ? 'New browse page' : `Edit: ${title || '(untitled)'}`}
      </h2>
      <button type="button" class="iwac-drawer__close" aria-label="Close" onclick={onClose}
        >×</button
      >
    </header>

    <form class="iwac-drawer__form" onsubmit={handleSubmit}>
      {#if validationError}
        <div class="iwac-drawer__error" role="alert">{validationError}</div>
      {/if}

      <div class="iwac-drawer__grid">
        <label class="iwac-drawer__field">
          <span class="iwac-drawer__label">Slug</span>
          <input
            type="text"
            bind:value={slug}
            class="iwac-drawer__input"
            pattern="[a-z][a-z0-9_-]*"
            placeholder="e.g. benin"
            required
            disabled={!isCreate}
          />
          <span class="iwac-drawer__hint">
            Public URL: <code>/browse/{slug || 'slug'}</code> — immutable after creation.
          </span>
        </label>

        <label class="iwac-drawer__field">
          <span class="iwac-drawer__label">Title</span>
          <input
            type="text"
            bind:value={title}
            class="iwac-drawer__input"
            placeholder="Displayed as the page heading"
            required
          />
        </label>

        <label class="iwac-drawer__field iwac-drawer__field--wide">
          <span class="iwac-drawer__label">Intro HTML (optional)</span>
          <textarea
            bind:value={introHtml}
            class="iwac-drawer__textarea"
            rows="3"
            placeholder="<p>Shown above the search results.</p>"
          ></textarea>
        </label>

        <label class="iwac-drawer__field iwac-drawer__field--wide">
          <span class="iwac-drawer__label">
            Locked filters <code>(Typesense filter_by)</code>
          </span>
          <input
            type="text"
            bind:value={lockedFilters}
            class="iwac-drawer__input iwac-drawer__input--mono"
            placeholder="country_ss:=`Burkina Faso`"
          />
          <span class="iwac-drawer__hint">
            Enforced server-side at scoped-key mint time. Use backticks around values with spaces or
            diacritics.
          </span>
        </label>

        <label class="iwac-drawer__field">
          <span class="iwac-drawer__label">Default sort</span>
          <select bind:value={defaultSort} class="iwac-drawer__input">
            {#each catalog.sorts as opt (opt.value)}
              <option value={opt.value}>{opt.label}</option>
            {/each}
          </select>
        </label>

        <label class="iwac-drawer__field">
          <span class="iwac-drawer__label">Results per page</span>
          <input
            type="number"
            bind:value={resultsPerPage}
            min="1"
            max="50"
            class="iwac-drawer__input"
          />
        </label>

        <label class="iwac-drawer__field">
          <span class="iwac-drawer__label">Display position</span>
          <input type="number" bind:value={position} class="iwac-drawer__input" />
          <span class="iwac-drawer__hint">Lower = earlier on the /browse landing grid.</span>
        </label>
      </div>

      <fieldset class="iwac-drawer__fieldset">
        <legend class="iwac-drawer__legend">Prominent facets</legend>
        <p class="iwac-drawer__hint">
          Shown above the fold on the browse page, in this order. Others collapse under "More
          filters".
        </p>
        <FacetPicker
          available={catalog.facets}
          selected={prominentFacets}
          onChange={(next) => (prominentFacets = next)}
        />
      </fieldset>

      <footer class="iwac-drawer__footer">
        <button
          type="button"
          class="iwac-btn iwac-btn--ghost"
          onclick={onClose}
          disabled={inFlight}
        >
          Cancel
        </button>
        <button type="submit" class="iwac-btn iwac-btn--primary" disabled={inFlight}>
          {#if inFlight}
            Saving…
          {:else if isCreate}
            Create
          {:else}
            Save changes
          {/if}
        </button>
      </footer>
    </form>
  </div>
{/if}

<style>
  .iwac-drawer-backdrop {
    position: fixed;
    inset: 0;
    background: rgba(0, 0, 0, 0.35);
    z-index: 100;
    animation: iwac-fade-in 150ms ease;
  }
  .iwac-drawer {
    position: fixed;
    inset-block: 0;
    inset-inline-end: 0;
    width: min(42rem, 100vw);
    background: var(--surface, #fff);
    box-shadow: -12px 0 32px rgba(0, 0, 0, 0.12);
    z-index: 101;
    display: flex;
    flex-direction: column;
    animation: iwac-slide-in 200ms cubic-bezier(0.2, 0, 0, 1);
  }
  @keyframes iwac-fade-in {
    from {
      opacity: 0;
    }
    to {
      opacity: 1;
    }
  }
  @keyframes iwac-slide-in {
    from {
      transform: translateX(100%);
    }
    to {
      transform: translateX(0);
    }
  }
  .iwac-drawer__header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: var(--space-md, 1rem) var(--space-lg, 1.5rem);
    border-bottom: 1px solid var(--border, #ccc);
  }
  .iwac-drawer__title {
    margin: 0;
    font-size: var(--text-xl, 1.25rem);
    color: var(--ink, #222);
  }
  .iwac-drawer__close {
    border: none;
    background: none;
    font-size: var(--text-2xl, 1.5rem);
    color: var(--muted, #666);
    cursor: pointer;
    width: 2rem;
    height: 2rem;
    border-radius: var(--radius-full, 9999px);
    display: inline-flex;
    align-items: center;
    justify-content: center;
    line-height: 1;
  }
  .iwac-drawer__close:hover {
    background: var(--surface-sunken, #f0f0f0);
    color: var(--ink, #222);
  }
  .iwac-drawer__form {
    overflow-y: auto;
    padding: var(--space-lg, 1.5rem);
    display: flex;
    flex-direction: column;
    gap: var(--space-lg, 1.5rem);
    flex: 1;
  }
  .iwac-drawer__error {
    background: color-mix(in srgb, var(--primary, #c66) 12%, var(--surface, #fff));
    border: 1px solid color-mix(in srgb, var(--primary, #c66) 35%, transparent);
    border-radius: var(--radius-md, 0.75rem);
    padding: var(--space-sm, 0.5rem) var(--space-md, 1rem);
    color: var(--ink-strong, var(--ink, #222));
    font-size: var(--text-sm, 0.9rem);
  }
  .iwac-drawer__grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: var(--space-md, 1rem);
  }
  .iwac-drawer__field {
    display: flex;
    flex-direction: column;
    gap: var(--space-xs, 0.25rem);
  }
  .iwac-drawer__field--wide {
    grid-column: 1 / -1;
  }
  .iwac-drawer__label {
    font-size: var(--text-sm, 0.9rem);
    font-weight: 600;
    color: var(--ink, #222);
  }
  .iwac-drawer__label code {
    font-weight: 400;
    color: var(--muted, #666);
    font-size: var(--text-xs, 0.75rem);
  }
  .iwac-drawer__input,
  .iwac-drawer__textarea {
    width: 100%;
    padding: var(--space-sm, 0.5rem);
    border: 1px solid var(--border, #ccc);
    border-radius: var(--radius-sm, 0.375rem);
    background: var(--surface, #fff);
    color: var(--ink, #222);
    font: inherit;
    font-size: var(--text-sm, 0.9rem);
    transition:
      border-color 120ms ease,
      box-shadow 120ms ease;
  }
  .iwac-drawer__input--mono {
    font-family: var(--font-mono, ui-monospace, monospace);
  }
  .iwac-drawer__input:focus,
  .iwac-drawer__textarea:focus {
    outline: none;
    border-color: var(--primary, #c66);
    box-shadow: var(--ring-focus, 0 0 0 3px rgba(204, 102, 102, 0.2));
  }
  .iwac-drawer__input:disabled {
    background: var(--surface-sunken, #f5f5f5);
    color: var(--muted, #666);
    cursor: not-allowed;
  }
  .iwac-drawer__hint {
    font-size: var(--text-xs, 0.75rem);
    color: var(--muted, #666);
  }
  .iwac-drawer__hint code {
    font-family: var(--font-mono, ui-monospace, monospace);
    background: var(--surface-sunken, #f5f5f5);
    padding: 0.125em 0.25em;
    border-radius: 0.125em;
  }
  .iwac-drawer__fieldset {
    border: 1px solid var(--border-light, #eee);
    border-radius: var(--radius-md, 0.75rem);
    padding: var(--space-md, 1rem);
    display: flex;
    flex-direction: column;
    gap: var(--space-sm, 0.5rem);
  }
  .iwac-drawer__legend {
    padding: 0 var(--space-xs, 0.25rem);
    font-weight: 600;
    color: var(--ink, #222);
  }
  .iwac-drawer__footer {
    display: flex;
    justify-content: flex-end;
    gap: var(--space-sm, 0.5rem);
    padding-top: var(--space-md, 1rem);
    border-top: 1px solid var(--border-light, #eee);
    margin-top: auto;
  }

  :global(.iwac-btn) {
    padding: var(--space-sm, 0.5rem) var(--space-md, 1rem);
    border-radius: var(--radius-sm, 0.375rem);
    font-size: var(--text-sm, 0.9rem);
    font-weight: 500;
    cursor: pointer;
    transition: all 120ms ease;
    border: 1px solid transparent;
  }
  :global(.iwac-btn:disabled) {
    opacity: 0.5;
    cursor: not-allowed;
  }
  :global(.iwac-btn--primary) {
    background: var(--primary, #c66);
    color: var(--surface, #fff);
    border-color: var(--primary, #c66);
  }
  :global(.iwac-btn--primary:hover:not(:disabled)) {
    filter: brightness(1.08);
  }
  :global(.iwac-btn--ghost) {
    background: transparent;
    color: var(--ink, #222);
    border-color: var(--border, #ccc);
  }
  :global(.iwac-btn--ghost:hover:not(:disabled)) {
    background: var(--surface-sunken, #f5f5f5);
  }
  :global(.iwac-btn--danger) {
    background: transparent;
    color: var(--primary, #c66);
    border-color: var(--primary, #c66);
  }
  :global(.iwac-btn--danger:hover:not(:disabled)) {
    background: var(--primary, #c66);
    color: var(--surface, #fff);
  }
</style>
