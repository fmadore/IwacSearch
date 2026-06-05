<script lang="ts">
  import type { BrowseConfig, FacetOption, SortOption } from '../lib/types';
  import Drawer from '../../svelte-shared/components/Drawer.svelte';
  import Button from '../../svelte-shared/components/Button.svelte';
  import FacetPicker from './FacetPicker.svelte';

  /**
   * Slide-in form for creating or editing one browse config.
   *
   * Create mode: `config` is null; on submit we call `onSave` with a
   * freshly-built draft (id: null).
   * Edit mode: `config` is the current row; edits are local to the
   * drawer until the user clicks Save.
   *
   * Drawer chrome (animation, backdrop, ESC, scroll lock, header with
   * × button) lives in the shared <Drawer> from src/svelte-shared/.
   * This file owns only the form-specific layout, validation, and
   * submit handling.
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
  const drawerTitle = $derived(isCreate ? 'New browse page' : `Edit: ${title || '(untitled)'}`);

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
</script>

<Drawer {open} title={drawerTitle} {onClose} side="right" panelClass="iwac-config-form">
  <form class="iwac-config-form__form" onsubmit={handleSubmit}>
    {#if validationError}
      <div class="iwac-config-form__error" role="alert">{validationError}</div>
    {/if}

    <div class="iwac-config-form__grid">
      <label class="iwac-config-form__field">
        <span class="iwac-config-form__label">Slug</span>
        <input
          type="text"
          bind:value={slug}
          class="iwac-config-form__input"
          pattern="[a-z][a-z0-9_-]*"
          placeholder="e.g. benin"
          required
          disabled={!isCreate}
        />
        <span class="iwac-config-form__hint">
          Public URL: <code>/browse/{slug || 'slug'}</code> — immutable after creation.
        </span>
      </label>

      <label class="iwac-config-form__field">
        <span class="iwac-config-form__label">Title</span>
        <input
          type="text"
          bind:value={title}
          class="iwac-config-form__input"
          placeholder="Displayed as the page heading"
          required
        />
      </label>

      <label class="iwac-config-form__field iwac-config-form__field--wide">
        <span class="iwac-config-form__label">Intro HTML (optional)</span>
        <textarea
          bind:value={introHtml}
          class="iwac-config-form__textarea"
          rows="3"
          placeholder="<p>Shown above the search results.</p>"
        ></textarea>
      </label>

      <label class="iwac-config-form__field iwac-config-form__field--wide">
        <span class="iwac-config-form__label">
          Locked filters <code>(Typesense filter_by)</code>
        </span>
        <input
          type="text"
          bind:value={lockedFilters}
          class="iwac-config-form__input iwac-config-form__input--mono"
          placeholder="country_ss:=`Burkina Faso`"
        />
        <span class="iwac-config-form__hint">
          Enforced server-side at scoped-key mint time. Use backticks around values with spaces or
          diacritics.
        </span>
      </label>

      <label class="iwac-config-form__field">
        <span class="iwac-config-form__label">Default sort</span>
        <select bind:value={defaultSort} class="iwac-config-form__input">
          {#each catalog.sorts as opt (opt.value)}
            <option value={opt.value}>{opt.label}</option>
          {/each}
        </select>
      </label>

      <label class="iwac-config-form__field">
        <span class="iwac-config-form__label">Results per page</span>
        <input
          type="number"
          bind:value={resultsPerPage}
          min="1"
          max="50"
          class="iwac-config-form__input"
        />
      </label>

      <label class="iwac-config-form__field">
        <span class="iwac-config-form__label">Display position</span>
        <input type="number" bind:value={position} class="iwac-config-form__input" />
        <span class="iwac-config-form__hint">Lower = earlier on the /browse landing grid.</span>
      </label>
    </div>

    <fieldset class="iwac-config-form__fieldset">
      <legend class="iwac-config-form__legend">Prominent facets</legend>
      <p class="iwac-config-form__hint">
        Shown above the fold on the browse page, in this order. Others collapse under "More
        filters".
      </p>
      <FacetPicker
        available={catalog.facets}
        selected={prominentFacets}
        onChange={(next) => (prominentFacets = next)}
      />
    </fieldset>

    <!-- <div>, not <footer>: avoids Omeka admin's bare-<footer> styling. -->
    <div class="iwac-config-form__footer">
      <Button variant="ghost" onclick={onClose} disabled={inFlight}>Cancel</Button>
      <Button type="submit" variant="primary" disabled={inFlight}>
        {#if inFlight}
          Saving…
        {:else if isCreate}
          Create
        {:else}
          Save changes
        {/if}
      </Button>
    </div>
  </form>
</Drawer>

<style>
  /*
   * Form-specific styling only. The drawer chrome (slide-in, backdrop,
   * header, close button) lives in src/svelte-shared/components/Drawer.svelte.
   *
   * Class prefix changed from .iwac-drawer__ to .iwac-config-form__ so
   * we never accidentally collide with the shared drawer's selectors.
   */
  .iwac-config-form__form {
    padding: var(--space-lg, 1.5rem);
    display: flex;
    flex-direction: column;
    gap: var(--space-lg, 1.5rem);
  }
  .iwac-config-form__error {
    background: color-mix(in oklab, var(--primary, #e64a19) 12%, var(--surface, #fdfdfd));
    border: 1px solid color-mix(in oklab, var(--primary, #e64a19) 35%, transparent);
    border-radius: var(--radius-md, 0.5rem);
    padding: var(--space-sm, 0.5rem) var(--space-md, 1rem);
    color: var(--ink-strong, var(--ink, #2c2f37));
    font-size: var(--text-sm, 0.9375rem);
  }
  .iwac-config-form__grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: var(--space-md, 1rem);
  }
  .iwac-config-form__field {
    display: flex;
    flex-direction: column;
    gap: var(--space-xs, 0.25rem);
  }
  .iwac-config-form__field--wide {
    grid-column: 1 / -1;
  }
  .iwac-config-form__label {
    font-size: var(--text-sm, 0.9375rem);
    font-weight: 600;
    color: var(--ink, #2c2f37);
  }
  .iwac-config-form__label code {
    font-weight: 400;
    color: var(--muted, #767880);
    font-size: var(--text-xs, 0.8125rem);
  }
  .iwac-config-form__input,
  .iwac-config-form__textarea {
    width: 100%;
    padding: var(--space-sm, 0.5rem);
    border: 1px solid var(--border, #d4d6da);
    border-radius: var(--radius-sm, 0.375rem);
    background: var(--surface, #fdfdfd);
    color: var(--ink, #2c2f37);
    font: inherit;
    font-size: var(--text-sm, 0.9375rem);
    transition:
      border-color 120ms ease,
      box-shadow 120ms ease;
  }
  .iwac-config-form__input--mono {
    font-family: var(--font-mono, ui-monospace, monospace);
  }
  .iwac-config-form__input:focus,
  .iwac-config-form__textarea:focus {
    outline: none;
    border-color: var(--primary, #e64a19);
    box-shadow: var(
      --ring-focus,
      0 0 0 3px color-mix(in oklab, var(--primary, #e64a19) 30%, transparent)
    );
  }
  .iwac-config-form__input:disabled {
    background: var(--surface-sunken, #f3f3f1);
    color: var(--muted, #767880);
    cursor: not-allowed;
  }
  .iwac-config-form__hint {
    font-size: var(--text-xs, 0.8125rem);
    color: var(--muted, #767880);
  }
  .iwac-config-form__hint code {
    font-family: var(--font-mono, ui-monospace, monospace);
    background: var(--surface-sunken, #f3f3f1);
    padding: 0.125em 0.25em;
    border-radius: 0.125em;
  }
  .iwac-config-form__fieldset {
    border: 1px solid var(--border-light, #e6e7eb);
    border-radius: var(--radius-md, 0.5rem);
    padding: var(--space-md, 1rem);
    display: flex;
    flex-direction: column;
    gap: var(--space-sm, 0.5rem);
  }
  .iwac-config-form__legend {
    padding: 0 var(--space-xs, 0.25rem);
    font-weight: 600;
    color: var(--ink, #2c2f37);
  }
  .iwac-config-form__footer {
    display: flex;
    justify-content: flex-end;
    gap: var(--space-sm, 0.5rem);
    padding-top: var(--space-md, 1rem);
    border-top: 1px solid var(--border-light, #e6e7eb);
    margin-top: auto;
  }
  /* `.iwac-btn` styles now live in src/svelte-shared/components/Button.svelte. */
</style>
