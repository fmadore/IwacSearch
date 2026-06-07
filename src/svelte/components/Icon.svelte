<script lang="ts">
  /**
   * Inline Bootstrap Icons (https://icons.getbootstrap.com), sized to 1em so
   * each icon inherits its font-size and color from the surrounding control.
   * Keeps the search UI on the same icon set as the rest of the IWAC stack —
   * the IWAC theme renders these same Bootstrap Icons as CSS mask images.
   */
  type IconName = 'search' | 'chevron-left' | 'chevron-right' | 'chevron-down' | 'x';

  interface Props {
    name: IconName;
  }

  const { name }: Props = $props();

  // Every Bootstrap Icon uses a `0 0 16 16` viewBox.
  const PATHS: Record<IconName, string> = {
    search:
      'M11.742 10.344a6.5 6.5 0 1 0-1.397 1.398h-.001q.044.06.098.115l3.85 3.85a1 1 0 0 0 1.415-1.414l-3.85-3.85a1 1 0 0 0-.115-.1zM12 6.5a5.5 5.5 0 1 1-11 0 5.5 5.5 0 0 1 11 0',
    'chevron-left':
      'M11.354 1.646a.5.5 0 0 1 0 .708L5.707 8l5.647 5.646a.5.5 0 0 1-.708.708l-6-6a.5.5 0 0 1 0-.708l6-6a.5.5 0 0 1 .708 0',
    'chevron-right':
      'M4.646 1.646a.5.5 0 0 1 .708 0l6 6a.5.5 0 0 1 0 .708l-6 6a.5.5 0 0 1-.708-.708L10.293 8 4.646 2.354a.5.5 0 0 1 0-.708',
    'chevron-down':
      'M1.646 4.646a.5.5 0 0 1 .708 0L8 10.293l5.646-5.647a.5.5 0 0 1 .708.708l-6 6a.5.5 0 0 1-.708 0l-6-6a.5.5 0 0 1 0-.708',
    x: 'M4.646 4.646a.5.5 0 0 1 .708 0L8 7.293l2.646-2.647a.5.5 0 0 1 .708.708L8.707 8l2.647 2.646a.5.5 0 0 1-.708.708L8 8.707l-2.646 2.647a.5.5 0 0 1-.708-.708L7.293 8 4.646 5.354a.5.5 0 0 1 0-.708',
  };
</script>

<svg
  class="iwac-icon"
  xmlns="http://www.w3.org/2000/svg"
  viewBox="0 0 16 16"
  width="1em"
  height="1em"
  fill="currentColor"
  aria-hidden="true"
  focusable="false"
>
  <path d={PATHS[name]} />
</svg>

<style>
  .iwac-icon {
    display: inline-block;
    vertical-align: -0.125em;
    flex-shrink: 0;
  }
</style>
