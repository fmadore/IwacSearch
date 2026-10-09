<script lang="ts">
  /**
   * The two faces of a semantic-only response (lib/semanticFallback.ts): a
   * query the keyword leg matched nothing for, answered only by the vector
   * leg's near neighbours.
   *
   *   shown = false  the offer under the empty state — one control, labelled
   *                  with what it will actually show. Offered, not asserted.
   *   shown = true   the banner over the rendered set, so it never appears
   *                  unlabelled, with the way back.
   *
   * Full-mode and compact surfaces render the same pair; App used to carry
   * two copies of each.
   */
  import { useI18n } from '../lib/i18n';

  interface Props {
    /** The vector leg's count — what the offer promises to show. */
    found: number;
    query: string;
    shown: boolean;
    onShow: () => void;
    onHide: () => void;
  }

  const { found, query, shown, onShow, onHide }: Props = $props();
  const { t, tp } = useI18n();
</script>

{#if shown}
  <div class="iwac-search__semantic-banner">
    <p class="iwac-search__semantic-banner-text">{t('semantic_only_banner', { q: query })}</p>
    <button type="button" class="iwac-search__semantic-btn iwac-quiet-btn" onclick={onHide}>
      {t('hide_semantic')}
    </button>
  </div>
{:else}
  <div class="iwac-search__semantic-offer">
    <button type="button" class="iwac-search__semantic-btn iwac-quiet-btn" onclick={onShow}>
      {tp('show_semantic', found)}
    </button>
  </div>
{/if}

<style>
  /*
   * Quiet outlined control in the toolbar vocabulary: this offers a weaker
   * kind of answer, so it must not out-shout the empty state's own "Clear all
   * filters".
   */
  .iwac-search__semantic-offer {
    display: flex;
    justify-content: center;
  }
  .iwac-search__semantic-banner {
    display: flex;
    align-items: baseline;
    flex-wrap: wrap;
    gap: var(--space-1, 0.25rem) var(--space-4, 1rem);
    padding-block-end: var(--space-2, 0.5rem);
    /* Hairline under, like the toolbar — a dateline over the set, not a card. */
    border-block-end: 1px solid var(--border-light, #e2e5e8);
  }
  .iwac-search__semantic-banner-text {
    margin: 0;
    color: var(--ink, #13161c);
    font-size: var(--text-sm, 0.9375rem);
  }
  /* The button itself is the module's quiet control (.iwac-quiet-btn). */
  .iwac-search__semantic-banner .iwac-search__semantic-btn {
    /* Tail of the banner line, like the summary strip's sort readout. */
    margin-inline-start: auto;
  }
</style>
