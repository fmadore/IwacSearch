<script module lang="ts">
  // Page-unique menu id for the trigger's aria-controls.
  let exportUid = 0;
  function nextExportMenuId(): string {
    return `iwac-export-menu-${++exportUid}`;
  }
</script>

<script lang="ts">
  import type { IwacDoc } from '../lib/types';
  import { EXPORT_MAX_HITS } from '../lib/typesense';
  import {
    download,
    exportFilename,
    serialize,
    EXPORT_FORMATS,
    type ExportFormat,
    type ExportMeta,
  } from '../lib/export';
  import { useI18n } from '../lib/i18n';
  import Icon from './Icon.svelte';
  import { refocus } from '../lib/refocus';

  /**
   * "Export" disclosure in the results toolbar: a small outlined trigger
   * (same control vocabulary as the Filters trigger / SortSelect) opening
   * a menu of download formats. Picking one fetches the CURRENT result
   * set (same query / filters / sort, capped at EXPORT_MAX_HITS),
   * serializes client-side and triggers a file download — no server
   * endpoint involved, the scoped key's constraints apply unchanged.
   */
  interface Props {
    /** Fetch the current result set's docs (capped) + the total found. */
    fetchDocs: () => Promise<{ docs: IwacDoc[]; found: number }>;
    /** The live query string, embedded in the export header metadata. */
    query: string;
    /** Total results of the current search — drives the cap hint. */
    found: number;
  }

  const { fetchDocs, query, found }: Props = $props();

  const { locale, t, formatNumber } = useI18n();
  const menuId = nextExportMenuId();

  let open = $state(false);
  let busy = $state(false);
  let error = $state<string | null>(null);
  let root: HTMLElement | null = $state(null);
  let trigger: HTMLButtonElement | null = $state(null);

  // Close when focus/clicks land outside the component.
  $effect(() => {
    if (!open) return;
    const onPointerDown = (e: PointerEvent): void => {
      if (root && !root.contains(e.target as Node)) {
        open = false;
      }
    };
    const onKeydown = (e: KeyboardEvent): void => {
      if (e.key === 'Escape') {
        open = false;
        // Focus was in the menu that just closed: back to its trigger.
        void refocus(trigger);
      }
    };
    window.addEventListener('pointerdown', onPointerDown);
    window.addEventListener('keydown', onKeydown);
    return () => {
      window.removeEventListener('pointerdown', onPointerDown);
      window.removeEventListener('keydown', onKeydown);
    };
  });

  async function run(format: ExportFormat): Promise<void> {
    if (busy) return;
    busy = true;
    error = null;
    try {
      const { docs, found: total } = await fetchDocs();
      const meta: ExportMeta = { query: query.trim(), found: total };
      const spec = EXPORT_FORMATS.find((f) => f.format === format)!;
      download(exportFilename(spec.extension), spec.mime, serialize(format, docs, meta, locale));
      open = false;
    } catch (e) {
      error = t('export_failed', { message: e instanceof Error ? e.message : String(e) });
    } finally {
      busy = false;
    }
    // The format button went with the menu (and the trigger was disabled
    // while busy): hand focus back to the trigger once it is enabled again.
    if (!open) void refocus(trigger);
  }
</script>

<div class="iwac-export" bind:this={root}>
  <!-- Disclosure pattern, NOT role="menu": menu semantics promise arrow-key
       navigation + focus management this simple format list doesn't implement.
       Plain buttons are natively Tab-reachable, which is the honest contract. -->
  <button
    bind:this={trigger}
    type="button"
    class="iwac-export__trigger iwac-quiet-btn"
    aria-expanded={open}
    aria-controls={open ? menuId : undefined}
    aria-label={t('export_results')}
    disabled={busy}
    onclick={() => (open = !open)}
  >
    <span class="iwac-export__icon" aria-hidden="true"><Icon name="download" /></span>
    <span class="iwac-export__label">{busy ? t('exporting') : t('export')}</span>
  </button>

  {#if open}
    <div class="iwac-export__menu" id={menuId} aria-label={t('export_results')}>
      {#each EXPORT_FORMATS as spec (spec.format)}
        <button
          type="button"
          class="iwac-export__item"
          disabled={busy}
          onclick={() => run(spec.format)}
        >
          {t(`export_${spec.format}`)}
        </button>
      {/each}
      {#if found > EXPORT_MAX_HITS}
        <p class="iwac-export__hint">
          {t('export_limit', { n: formatNumber(EXPORT_MAX_HITS) })}
        </p>
      {/if}
      {#if error}
        <p class="iwac-export__error" role="alert">{error}</p>
      {/if}
    </div>
  {/if}
</div>

<style>
  .iwac-export {
    position: relative;
    display: inline-flex;
  }

  /* The trigger is the module's quiet control (.iwac-quiet-btn, in
     asset/css/iwac-search.css), at the toolbar's height. */
  .iwac-export__trigger {
    height: var(--size-control-md, 2.5rem);
  }
  .iwac-export__trigger:disabled {
    opacity: 0.6;
    cursor: progress;
  }
  .iwac-export__icon {
    display: inline-flex;
    align-items: center;
    font-size: 0.9em;
  }
  /*
   * On the narrowest phones the result-controls bar goes icon-forward (the view
   * toggle and Filters trigger do the same at this breakpoint), so the Export
   * label collapses to its download glyph — the button keeps its aria-label.
   */
  @media (max-width: 399px) {
    .iwac-export__trigger {
      padding-inline: var(--space-2, 0.5rem);
    }
    .iwac-export__label {
      /* Visually hidden but kept for assistive tech. */
      position: absolute;
      width: 1px;
      height: 1px;
      padding: 0;
      margin: -1px;
      overflow: hidden;
      clip: rect(0 0 0 0);
      white-space: nowrap;
      border: 0;
    }
  }

  /* Floating format menu — same chrome as the suggest dropdown. */
  .iwac-export__menu {
    position: absolute;
    inset-inline-end: 0;
    inset-block-start: calc(100% + var(--space-1, 0.25rem));
    z-index: var(--z-dropdown, 100);
    min-width: 14rem;
    background: var(--surface, #fdfcfb);
    border: 1px solid var(--border, #ced1d6);
    border-radius: var(--radius-md, 0.5rem);
    /* The theme's floating-panel shadow. It used to be a hand-rolled neutral
       black pair, copy-pasted into three files and warm-blind in both themes;
       --shadow-lg is the published overlay step and carries its own dark
       variant. */
    box-shadow: var(
      --shadow-lg,
      0 10px 15px -3px rgba(9, 11, 15, 0.12),
      0 4px 6px -4px rgba(20, 22, 27, 0.06)
    );
    overflow: hidden;
  }
  .iwac-export__item {
    display: block;
    width: 100%;
    margin: 0;
    padding: var(--space-2, 0.5rem) var(--space-4, 1rem);
    appearance: none;
    background: transparent;
    border: 0;
    border-bottom: 1px solid var(--border-light, #e2e5e8);
    box-shadow: none;
    color: var(--ink, #13161c);
    font: inherit;
    font-size: var(--text-sm, 0.9375rem);
    text-align: start;
    cursor: pointer;
    transition: background 80ms ease;
  }
  .iwac-export__item:last-of-type {
    border-bottom: none;
  }
  .iwac-export__item:hover,
  .iwac-export__item:focus-visible {
    background: color-mix(in oklab, var(--primary, #ce4115) 8%, var(--surface, #fdfcfb));
  }
  .iwac-export__item:focus-visible {
    /* Inset: the menu is a rounded, clipped panel and these items run its full
       width, so an outset outline would be trimmed on both edges. */
    outline: var(--focus-outline, 2px solid #ce4115);
    outline-offset: -2px;
  }
  .iwac-export__hint,
  .iwac-export__error {
    margin: 0;
    padding: var(--space-1, 0.25rem) var(--space-4, 1rem) var(--space-2, 0.5rem);
    font-size: var(--text-xs, 0.8125rem);
    color: var(--muted, #66696e);
    border-top: 1px solid var(--border-light, #e2e5e8);
  }
  .iwac-export__error {
    color: var(--error, #c9222b);
  }
</style>
