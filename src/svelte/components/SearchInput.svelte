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

  interface Props {
    value: string;
    placeholder?: string;
    onChange: (next: string) => void;
  }

  const { value, placeholder = '', onChange }: Props = $props();

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
  <label class="iwac-input__visually-hidden" for="iwac-q">Search</label>
  <input
    id="iwac-q"
    class="iwac-input__field"
    type="search"
    autocomplete="off"
    autocapitalize="off"
    spellcheck="false"
    inputmode="search"
    {placeholder}
    value={local}
    oninput={handleInput}
  />
  {#if local !== ''}
    <button type="button" class="iwac-input__clear" aria-label="Clear search" onclick={handleClear}
      ><Icon name="x" /></button
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
  .iwac-input__visually-hidden {
    position: absolute;
    width: 1px;
    height: 1px;
    padding: 0;
    margin: -1px;
    overflow: hidden;
    clip: rect(0, 0, 0, 0);
    white-space: nowrap;
    border: 0;
  }
</style>
