<script lang="ts">
  /**
   * Debounced text input. Emits via onChange after 250 ms of typing
   * stillness — matches the M5 typeahead spec, so M5 won't need to
   * touch this component.
   *
   * URL state sync (write side) lives in App.svelte rather than here
   * to keep this component standalone-testable.
   */

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
  $effect(() => {
    if (value !== local) {
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
      >×</button
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
    font-size: var(--text-base, 1rem);
    color: var(--ink, #222);
    background: var(--surface, #fff);
    border: 1px solid var(--border, #ccc);
    border-radius: var(--radius-md, 0.75rem);
    transition:
      border-color 120ms ease,
      box-shadow 120ms ease;
  }
  .iwac-input__field:focus {
    outline: none;
    border-color: var(--primary, #c66);
    box-shadow: var(--ring-focus, 0 0 0 3px rgba(0, 0, 0, 0.1));
  }
  .iwac-input__clear {
    position: absolute;
    inset-inline-end: var(--space-sm, 0.5rem);
    width: var(--size-control-sm, 2.25rem);
    height: var(--size-control-sm, 2.25rem);
    border: none;
    background: transparent;
    color: var(--muted, #666);
    font-size: var(--text-xl, 1.5rem);
    line-height: 1;
    cursor: pointer;
    border-radius: var(--radius-full, 9999px);
  }
  .iwac-input__clear:hover {
    background: var(--surface-sunken, #f0f0f0);
    color: var(--ink, #222);
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
