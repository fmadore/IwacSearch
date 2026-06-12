<script lang="ts">
  /**
   * Debounced text input. Emits via onChange after 250 ms of typing
   * stillness — matches the M5 typeahead spec, so M5 won't need to
   * touch this component.
   *
   * URL state sync (write side) lives in App.svelte rather than here
   * to keep this component standalone-testable.
   */

  import { untrack } from 'svelte';
  import Icon from './Icon.svelte';
  import { useI18n } from '../lib/i18n';

  interface Props {
    value: string;
    placeholder?: string;
    onChange: (next: string) => void;
    /**
     * ARIA combobox wiring. The parent (App.svelte) owns the suggestion
     * dropdown, so it passes the listbox id, whether it's expanded, and the
     * id of the active option so this input can advertise itself as a proper
     * combobox (input that controls a popup listbox). All optional: when the
     * surface has no typeahead, the input renders as a plain search box.
     */
    listboxId?: string;
    expanded?: boolean;
    activeDescendant?: string | null;
  }

  const {
    value,
    placeholder = '',
    onChange,
    listboxId,
    expanded = false,
    activeDescendant = null,
  }: Props = $props();

  const { t } = useI18n();

  // svelte-ignore state_referenced_locally
  // Initial seed only; the $effect below re-syncs if the parent pushes
  // a new `value` (e.g. URL state restore, programmatic reset).
  let local = $state(value);
  let timer = $state<number | null>(null);

  // Keep `local` in sync if the parent resets us (e.g. URL state push).
  // Read `local` via untrack so typing into the input — which updates
  // `local` — does not re-trigger this effect and wipe the keystroke
  // during the 250 ms debounce window before the parent's `value` catches up.
  $effect(() => {
    if (value !== untrack(() => local)) {
      local = value;
    }
  });

  function handleInput(e: Event): void {
    const target = e.target as HTMLInputElement;
    local = target.value;
    if (timer !== null) {
      clearTimeout(timer);
    }
    timer = window.setTimeout(() => {
      onChange(local);
      timer = null;
    }, 250);
  }

  function handleClear(): void {
    local = '';
    if (timer !== null) {
      clearTimeout(timer);
      timer = null;
    }
    onChange('');
  }
</script>

<div class="iwac-input">
  <!--
    Combobox input. aria-label gives it an accessible name without a separate
    <label for> (which would need a unique id per mount — multiple search
    surfaces can share a page). aria-controls / aria-activedescendant are only
    set while the listbox is actually in the DOM (expanded), so they never
    dangle at a removed element. role/aria-* are inert when listboxId is unset.
  -->
  <input
    class="iwac-input__field"
    type="search"
    role={listboxId ? 'combobox' : undefined}
    aria-label={t('search_placeholder')}
    aria-autocomplete={listboxId ? 'list' : undefined}
    aria-expanded={listboxId ? expanded : undefined}
    aria-controls={listboxId && expanded ? listboxId : undefined}
    aria-activedescendant={listboxId && expanded ? (activeDescendant ?? undefined) : undefined}
    autocomplete="off"
    autocapitalize="off"
    spellcheck="false"
    inputmode="search"
    {placeholder}
    value={local}
    oninput={handleInput}
  />
  {#if local !== ''}
    <button
      type="button"
      class="iwac-input__clear"
      aria-label={t('clear_search')}
      onclick={handleClear}><Icon name="x" /></button
    >
  {/if}
</div>

<style>
  .iwac-input {
    position: relative;
    display: flex;
    align-items: center;
  }
  .iwac-input__field {
    width: 100%;
    /* Theme global field rule adds margin-bottom — the shell owns rhythm. */
    margin: 0;
    height: var(--size-control-lg, 2.75rem);
    padding-inline: var(--space-md, 1rem);
    padding-inline-end: var(--space-2xl, 3rem);
    font-size: var(--text-base, 1.0625rem);
    color: var(--ink, #2c2f37);
    background: var(--surface, #fdfdfd);
    border: 1px solid var(--border, #d4d6da);
    border-radius: var(--radius-md, 0.5rem);
    box-shadow: var(--shadow-xs, 0 1px 2px rgba(0, 0, 0, 0.04));
    transition:
      border-color var(--transition-fast, 150ms ease),
      box-shadow var(--transition-fast, 150ms ease);
  }
  .iwac-input__field::placeholder {
    color: var(--muted, #767880);
  }
  /* Suppress the browser's native type=search clear glyph. We render our
     own .iwac-input__clear button; without this, Chrome/Safari show a
     second × right next to it (the duplicate-X the user reported). */
  .iwac-input__field::-webkit-search-cancel-button {
    -webkit-appearance: none;
    appearance: none;
    display: none;
  }
  .iwac-input__field:hover {
    border-color: var(--border-strong, var(--border, #d4d6da));
  }
  .iwac-input__field:focus {
    outline: none;
    border-color: var(--primary, #e64a19);
    box-shadow: var(--ring-focus, 0 0 0 3px rgba(0, 0, 0, 0.1));
  }
  .iwac-input__clear {
    position: absolute;
    inset-inline-end: var(--space-sm, 0.5rem);
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: var(--size-control-sm, 2.25rem);
    height: var(--size-control-sm, 2.25rem);
    padding: 0;
    border: none;
    background: transparent;
    box-shadow: none;
    color: var(--muted, #767880);
    font-size: var(--text-lg, 1.1875rem);
    line-height: 1;
    cursor: pointer;
    border-radius: var(--radius-full, 9999px);
  }
  .iwac-input__clear:hover {
    background: var(--surface-sunken, #f3f3f1);
    color: var(--ink, #2c2f37);
    box-shadow: none;
    transform: none;
  }
</style>
