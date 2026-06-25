<script lang="ts">
  /**
   * Tiny inline mentions-over-time sparkline for entity cards (design review
   * §03B). Draws a dense per-year count series as a polyline with an end dot,
   * bridging the search cards to the IwacVisualizations dashboards.
   *
   * Colour is inherited via `currentColor`, so the parent sets the stroke to the
   * entity's categorical colour (`--type-entity-personnes` etc.) the same way
   * the type dot does — read from the theme token at runtime, never hardcoded.
   * Renders nothing for fewer than two points (no line to draw).
   */
  interface Props {
    /** Dense per-year counts (gaps already filled as 0). */
    values: number[];
    width?: number;
    height?: number;
  }

  const { values, width = 150, height = 36 }: Props = $props();

  const geometry = $derived.by(() => {
    if (values.length < 2) return null;
    const max = Math.max(...values, 1);
    const pad = 3; // keep the 2px stroke + end dot inside the box
    const stepX = width / (values.length - 1);
    const pts = values.map((v, i) => {
      const x = i * stepX;
      const y = height - pad - (v / max) * (height - pad * 2);
      return { x, y };
    });
    const last = pts[pts.length - 1];
    return {
      line: pts.map((p) => `${p.x.toFixed(1)},${p.y.toFixed(1)}`).join(' '),
      lastX: last.x.toFixed(1),
      lastY: last.y.toFixed(1),
    };
  });
</script>

{#if geometry}
  <svg
    class="iwac-spark"
    {width}
    {height}
    viewBox="0 0 {width} {height}"
    preserveAspectRatio="none"
    aria-hidden="true"
    focusable="false"
  >
    <polyline
      points={geometry.line}
      fill="none"
      stroke="currentColor"
      stroke-width="2"
      stroke-linejoin="round"
      stroke-linecap="round"
    />
    <circle cx={geometry.lastX} cy={geometry.lastY} r="2.5" fill="currentColor" />
  </svg>
{/if}

<style>
  .iwac-spark {
    display: block;
    overflow: visible;
  }
</style>
