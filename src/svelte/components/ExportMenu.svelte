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

  const { locale, t } = useI18n();
  const menuId = nextExportMenuId();

  let open = $state(false);
  let busy = $state(false);
  let error = $state<string | null>(null);
  let root: HTMLElement | null = $state(null);

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
  }
</script>

<div class="iwac-export" bind:this={root}>
  <button
    type="button"
    class="iwac-export__trigger"
    aria-haspopup="true"
    aria-expanded={open}
    aria-controls={open ? menuId : undefined}
    aria-label={t('export_results')}
    disabled={busy}
    onclick={() => (open = !open)}
  >
    <span class="iwac-export__icon" aria-hidden="true"><Icon name="download" /></span>
    {busy ? t('exporting') : t('export')}
  </button>

  {#if open}
    <div class="iwac-export__menu" id={menuId} role="menu" aria-label={t('export_results')}>
      {#each EXPORT_FORMATS as spec (spec.format)}
        <button
          type="button"
          class="iwac-export__item"
          role="menuitem"
          disabled={busy}
          onclick={() => run(spec.format)}
        >
          {t(`export_${spec.format}`)}
        </button>
      {/each}
      {#if found > EXPORT_MAX_HITS}
        <p class="iwac-export__hint">
          {t('export_limit', { n: EXPORT_MAX_HITS.toLocaleString() })}
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

  /*
   * Trigger mirrors the toolbar's control vocabulary (Filters trigger /
   * SortSelect): outlined, surface background, primary on hover. The IWAC
   * theme paints every <button> primary + glow + hover-translate, so the
   * resets below are deliberate.
   */
  .iwac-export__trigger {
    display: inline-flex;
    align-items: center;
    gap: var(--space-xs, 0.25rem);
    height: var(--size-control-md, 2.5rem);
    padding-inline: var(--space-md, 1rem);
    border: 1px solid var(--border, #d4d6da);
    border-radius: var(--radius-md, 0.5rem);
    background: var(--surface, #fdfdfd);
    color: var(--ink, #2c2f37);
    box-shadow: none;
    font: inherit;
    font-size: var(--text-sm, 0.9375rem);
    font-weight: 500;
    cursor: pointer;
    transition:
      border-color var(--transition-fast, 150ms ease),
      color var(--transition-fast, 150ms ease);
  }
  .iwac-export__trigger:hover {
    background: var(--surface, #fdfdfd);
    border-color: var(--primary, #e64a19);
    color: var(--primary, #e64a19);
    box-shadow: none;
    transform: none;
  }
  .iwac-export__trigger:focus-visible {
    outline: none;
    box-shadow: var(--ring-focus, 0 0 0 3px rgba(0, 0, 0, 0.1));
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

  /* Floating format menu — same chrome as the suggest dropdown. */
  .iwac-export__menu {
    position: absolute;
    inset-inline-end: 0;
    inset-block-start: calc(100% + var(--space-2xs, 0.25rem));
    z-index: 30;
    min-width: 14rem;
    background: var(--surface, #fdfdfd);
    border: 1px solid var(--border, #d4d6da);
    border-radius: var(--radius-md, 0.5rem);
    box-shadow:
      0 4px 12px rgba(0, 0, 0, 0.08),
      0 1px 3px rgba(0, 0, 0, 0.05);
    overflow: hidden;
  }
  .iwac-export__item {
    display: block;
    width: 100%;
    margin: 0;
    padding: var(--space-sm, 0.5rem) var(--space-md, 1rem);
    appearance: none;
    background: transparent;
    border: 0;
    border-bottom: 1px solid var(--border-light, #e6e7eb);
    box-shadow: none;
    color: var(--ink, #2c2f37);
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
    background: color-mix(in oklab, var(--primary, #e64a19) 8%, var(--surface, #fdfdfd));
    box-shadow: none;
    transform: none;
    outline: none;
  }
  .iwac-export__hint,
  .iwac-export__error {
    margin: 0;
    padding: var(--space-xs, 0.25rem) var(--space-md, 1rem) var(--space-sm, 0.5rem);
    font-size: var(--text-xs, 0.8125rem);
    color: var(--muted, #767880);
    border-top: 1px solid var(--border-light, #e6e7eb);
  }
  .iwac-export__error {
    color: var(--error, #c0392b);
  }
</style>
