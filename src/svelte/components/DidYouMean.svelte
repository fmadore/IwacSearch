<script lang="ts">
  /**
   * "Did you mean" banner on zero-result queries: entity chips from the
   * typo-tolerant suggest path (facet_query + the alias-reconciling entity
   * index), so a near-miss spelling ("Tidjaniya") offers the canonical entity.
   * Picking one applies it as a filter.
   */
  import type { EntitySuggestion } from '../lib/types';
  import { facetLabel, useI18n } from '../lib/i18n';

  interface Props {
    suggestions: EntitySuggestion[];
    onPick: (field: string, value: string) => void;
  }

  const { suggestions, onPick }: Props = $props();
  const { t, locale } = useI18n();
</script>

<div class="iwac-search__didyoumean" role="status">
  <span class="iwac-search__didyoumean-label">{t('did_you_mean')}</span>
  {#each suggestions as s (s.field + s.value)}
    <button
      type="button"
      class="iwac-search__didyoumean-chip"
      onclick={() => onPick(s.field, s.value)}
    >
      {s.value}
      <span class="iwac-search__didyoumean-tag">{facetLabel(s.field, locale)}</span>
    </button>
  {/each}
</div>

<style>
  .iwac-search__didyoumean {
    display: flex;
    align-items: center;
    flex-wrap: wrap;
    gap: var(--space-2, 0.5rem);
    padding: var(--space-2, 0.5rem) 0;
  }
  .iwac-search__didyoumean-label {
    color: var(--muted, #66696e);
    font-size: var(--text-sm, 0.9375rem);
  }
  .iwac-search__didyoumean-chip {
    display: inline-flex;
    align-items: center;
    gap: var(--space-1, 0.25rem);
    padding: 0.25rem var(--space-2, 0.5rem);
    border: 1px solid var(--border, #ced1d6);
    border-radius: var(--radius-full, 9999px);
    background: var(--surface, #fdfcfb);
    color: var(--ink, #13161c);
    box-shadow: none;
    font: inherit;
    font-size: var(--text-sm, 0.9375rem);
    cursor: pointer;
    transition:
      border-color var(--transition-fast, 150ms cubic-bezier(0.25, 1, 0.5, 1)),
      color var(--transition-fast, 150ms cubic-bezier(0.25, 1, 0.5, 1));
  }
  .iwac-search__didyoumean-chip:hover,
  .iwac-search__didyoumean-chip:focus-visible {
    border-color: var(--primary, #ce4115);
    color: var(--primary, #ce4115);
    background: var(--surface, #fdfcfb);
    box-shadow: none;
    transform: none;
  }
  .iwac-search__didyoumean-chip:focus-visible {
    outline: var(--focus-outline, 2px solid #ce4115);
    outline-offset: 2px;
  }
  .iwac-search__didyoumean-tag {
    font-size: var(--text-xs, 0.8125rem);
    color: var(--muted, #66696e);
    background: var(--surface-sunken, #f4f1ef);
    padding: 0.0625rem 0.375rem;
    border-radius: var(--radius-full, 9999px);
  }
</style>
