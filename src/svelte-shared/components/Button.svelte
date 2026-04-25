<script lang="ts">
  import type { Snippet } from 'svelte';

  /**
   * Single button + link primitive used across the admin (and any
   * future public surface that wants a styled CTA). Replaces the loose
   * `<button class="iwac-btn iwac-btn--primary">` pattern that used to
   * be sprinkled across three components, with the styles declared
   * once via :global() in ConfigFormDrawer.svelte.
   *
   * Renders an <a> when `href` is set, a <button> otherwise — same
   * visual chrome both ways. The link variant honours `disabled` by
   * dropping out of the tab order and applying the disabled style;
   * we don't change the rendered tag based on disabled state because
   * the markup churn isn't worth it.
   *
   * Variants:
   *   - primary  filled, brand colour, for the main action in a form
   *   - ghost    outlined, neutral, for secondary actions
   *   - danger   outlined, brand red, for destructive actions
   *
   * Sizes:
   *   - md       default
   *   - sm       inline table actions, dense controls
   *
   * Slot: render the label via the default `children` snippet.
   */

  type Variant = 'primary' | 'ghost' | 'danger';
  type Size = 'md' | 'sm';

  interface Props {
    type?: 'button' | 'submit' | 'reset';
    variant?: Variant;
    size?: Size;
    disabled?: boolean;
    href?: string;
    /** Only meaningful when `href` is set. */
    target?: '_blank' | '_self' | '_parent' | '_top';
    /** Defaults to "noopener" when target="_blank", per security default. */
    rel?: string;
    onclick?: (e: MouseEvent) => void;
    /** Override the accessible label when the button content is icon-only. */
    ariaLabel?: string;
    children: Snippet;
  }

  const {
    type = 'button',
    variant = 'ghost',
    size = 'md',
    disabled = false,
    href,
    target,
    rel,
    onclick,
    ariaLabel,
    children,
  }: Props = $props();

  const cls = $derived(`iwac-btn iwac-btn--${variant} iwac-btn--${size}`);

  // Default rel for new-tab links — same security baseline browsers
  // would apply, but explicit so reviewers don't have to remember.
  const safeRel = $derived(target === '_blank' ? (rel ?? 'noopener') : rel);
</script>

{#if href}
  <a
    {href}
    {target}
    rel={safeRel}
    aria-label={ariaLabel}
    aria-disabled={disabled || undefined}
    class={cls}
    class:iwac-btn--disabled={disabled}
    tabindex={disabled ? -1 : undefined}
  >
    {@render children()}
  </a>
{:else}
  <button {type} {disabled} {onclick} aria-label={ariaLabel} class={cls}>
    {@render children()}
  </button>
{/if}

<style>
  /*
   * Button styles emitted into both bundles (the public bundle imports
   * via the shared barrel even when it doesn't call <Button> yet — the
   * design tokens stay aligned).
   *
   * Class names stay :global so the same CSS applies whether a child
   * consumer renders <Button> or hand-rolls a `<button class="iwac-btn">`
   * for an exotic case (e.g. inside a third-party admin partial).
   */
  :global(.iwac-btn) {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: var(--space-xs, 0.25rem);
    padding: var(--space-sm, 0.5rem) var(--space-md, 1rem);
    border: 1px solid transparent;
    border-radius: var(--radius-sm, 0.375rem);
    font: inherit;
    font-size: var(--text-sm, 0.9rem);
    font-weight: 500;
    line-height: 1.2;
    cursor: pointer;
    text-decoration: none;
    transition: all 120ms ease;
  }
  :global(.iwac-btn:disabled),
  :global(.iwac-btn.iwac-btn--disabled) {
    opacity: 0.5;
    cursor: not-allowed;
    pointer-events: none;
  }

  /* Variants */
  :global(.iwac-btn--primary) {
    background: var(--primary, #c66);
    color: var(--surface, #fff);
    border-color: var(--primary, #c66);
  }
  :global(.iwac-btn--primary:hover:not(:disabled):not(.iwac-btn--disabled)) {
    filter: brightness(1.08);
  }

  :global(.iwac-btn--ghost) {
    background: transparent;
    color: var(--ink, #222);
    border-color: var(--border, #ccc);
  }
  :global(.iwac-btn--ghost:hover:not(:disabled):not(.iwac-btn--disabled)) {
    background: var(--surface-sunken, #f5f5f5);
  }

  :global(.iwac-btn--danger) {
    background: transparent;
    color: var(--primary, #c66);
    border-color: var(--primary, #c66);
  }
  :global(.iwac-btn--danger:hover:not(:disabled):not(.iwac-btn--disabled)) {
    background: var(--primary, #c66);
    color: var(--surface, #fff);
  }

  /* Sizes */
  :global(.iwac-btn--sm) {
    padding: 0.25rem 0.625rem;
    font-size: var(--text-xs, 0.75rem);
  }

  :global(.iwac-btn:focus-visible) {
    outline: none;
    box-shadow: var(--ring-focus, 0 0 0 3px rgba(204, 102, 102, 0.3));
  }
</style>
