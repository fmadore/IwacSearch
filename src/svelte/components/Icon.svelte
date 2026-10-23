<script lang="ts">
  /**
   * Inline Lucide icons (https://lucide.dev, ISC licence), sized to 1em so
   * each icon inherits its font-size and color from the surrounding control.
   * One icon library across the IWAC stack: the IWAC theme draws its chrome
   * with Lucide (header search, chevrons, close, theme and language toggles)
   * and IwacVisualizations' buttons use the same set, so the search box here
   * and the one in the site header now look like the same control.
   */
  type IconName =
    | 'search'
    | 'chevron-left'
    | 'chevron-right'
    | 'chevron-down'
    | 'x'
    | 'person'
    | 'newspaper'
    | 'globe'
    | 'journal'
    | 'book'
    | 'download'
    | 'list'
    | 'grid'
    | 'image'
    | 'filter'
    | 'map'
    | 'link'
    | 'clock'
    | 'camera-video'
    | 'play'
    | 'external';

  interface Props {
    name: IconName;
  }

  const { name }: Props = $props();

  // Every Lucide icon is drawn on a `0 0 24 24` grid with a 2px round
  // stroke. The names are this component's own (callers predate the switch
  // from Bootstrap Icons); the Lucide icon each one draws is noted beside
  // it. Rects and circles are written as paths, so every entry is a list of
  // `d` strings, one <path> each — a relative `m` opening a later subpath
  // would otherwise resolve against the previous one's end point.
  const PATHS: Record<IconName, string[]> = {
    search: ['m21 21-4.34-4.34', 'M3 11a8 8 0 1 0 16 0a8 8 0 1 0 -16 0'],
    'chevron-left': ['m15 18-6-6 6-6'],
    'chevron-right': ['m9 18 6-6-6-6'],
    'chevron-down': ['m6 9 6 6 6-6'],
    x: ['M18 6 6 18', 'm6 6 12 12'],
    // `user`
    person: ['M19 21v-2a4 4 0 0 0-4-4H9a4 4 0 0 0-4 4v2', 'M8 7a4 4 0 1 0 8 0a4 4 0 1 0 -8 0'],
    newspaper: [
      'M15 18h-5',
      'M18 14h-8',
      'M4 22h16a2 2 0 0 0 2-2V4a2 2 0 0 0-2-2H8a2 2 0 0 0-2 2v16a2 2 0 0 1-4 0v-9a2 2 0 0 1 2-2h2',
      'M11 6h6a1 1 0 0 1 1 1v2a1 1 0 0 1 -1 1h-6a1 1 0 0 1 -1 -1v-2a1 1 0 0 1 1 -1z',
    ],
    globe: [
      'M2 12a10 10 0 1 0 20 0a10 10 0 1 0 -20 0',
      'M12 2a14.5 14.5 0 0 0 0 20 14.5 14.5 0 0 0 0-20',
      'M2 12h20',
    ],
    // `notebook-text`
    journal: [
      'M2 6h4',
      'M2 10h4',
      'M2 14h4',
      'M2 18h4',
      'M6 2h12a2 2 0 0 1 2 2v16a2 2 0 0 1 -2 2h-12a2 2 0 0 1 -2 -2v-16a2 2 0 0 1 2 -2z',
      'M9.5 8h5',
      'M9.5 12H16',
      'M9.5 16H14',
    ],
    // `book-open`
    book: [
      'M12 5v16',
      'M20.001 19A2 2 0 0022 17V5a2 2 0 00-1.999-2L16 3.002A5 5 0 0012 5a5 5 0 00-4-2H4a2 2 0 00-2 2v12a2 2 0 001.999 2H8a5 5 0 014 2 5 5 0 014-2z',
    ],
    download: ['M12 15V3', 'M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4', 'm7 10 5 5 5-5'],
    // `list` + `layout-grid` drive the List/Gallery view toggle; `image` is
    // the gallery placeholder for items with no thumbnail.
    list: ['M3 5h.01', 'M3 12h.01', 'M3 19h.01', 'M8 5h13', 'M8 12h13', 'M8 19h13'],
    grid: [
      'M4 3h5a1 1 0 0 1 1 1v5a1 1 0 0 1 -1 1h-5a1 1 0 0 1 -1 -1v-5a1 1 0 0 1 1 -1z',
      'M15 3h5a1 1 0 0 1 1 1v5a1 1 0 0 1 -1 1h-5a1 1 0 0 1 -1 -1v-5a1 1 0 0 1 1 -1z',
      'M15 14h5a1 1 0 0 1 1 1v5a1 1 0 0 1 -1 1h-5a1 1 0 0 1 -1 -1v-5a1 1 0 0 1 1 -1z',
      'M4 14h5a1 1 0 0 1 1 1v5a1 1 0 0 1 -1 1h-5a1 1 0 0 1 -1 -1v-5a1 1 0 0 1 1 -1z',
    ],
    image: [
      'M5 3h14a2 2 0 0 1 2 2v14a2 2 0 0 1 -2 2h-14a2 2 0 0 1 -2 -2v-14a2 2 0 0 1 2 -2z',
      'M7 9a2 2 0 1 0 4 0a2 2 0 1 0 -4 0',
      'm21 15-3.086-3.086a2 2 0 0 0-2.828 0L6 21',
    ],
    // `funnel` — the mobile Filters trigger.
    filter: [
      'M10 20a1 1 0 0 0 .553.895l2 1A1 1 0 0 0 14 21v-7a2 2 0 0 1 .517-1.341L21.74 4.67A1 1 0 0 0 21 3H3a1 1 0 0 0-.742 1.67l7.225 7.989A2 2 0 0 1 10 14z',
    ],
    // `map-pin` — the Map view segment on the entity index.
    map: [
      'M20 10c0 4.993-5.539 10.193-7.399 11.799a1 1 0 0 1-1.202 0C9.539 20.193 4 14.993 4 10a8 8 0 0 1 16 0',
      'M9 10a3 3 0 1 0 6 0a3 3 0 1 0 -6 0',
    ],
    // The copy-link action in the result controls.
    link: [
      'M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71',
      'M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71',
    ],
    // Recent-searches rows in the typeahead, and the running time on an
    // audiovisual result card.
    clock: ['M2 12a10 10 0 1 0 20 0a10 10 0 1 0 -20 0', 'M12 6v6l4 2'],
    // `video` — the channel/producer chip on an audiovisual card (where a
    // press card shows `newspaper`).
    'camera-video': [
      'm16 13 5.223 3.482a.5.5 0 0 0 .777-.416V7.87a.5.5 0 0 0-.752-.432L16 10.5',
      'M4 6h10a2 2 0 0 1 2 2v8a2 2 0 0 1 -2 2h-10a2 2 0 0 1 -2 -2v-8a2 2 0 0 1 2 -2z',
    ],
    // The outbound "Watch on YouTube" action: filled, as media controls are.
    play: ['M5 5a2 2 0 0 1 3.008-1.728l11.997 6.998a2 2 0 0 1 .003 3.458l-12 7A2 2 0 0 1 5 19z'],
    // `square-arrow-out-up-right` — closes a card's DOI / Wikidata link, as
    // the theme's box-arrow-up-right mask closes the same links on the item page.
    external: [
      'M21 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h6',
      'm21 3-9 9',
      'M15 3h6v6',
    ],
  };

  const FILLED: Partial<Record<IconName, true>> = { play: true };
</script>

<svg
  class="iwac-icon"
  xmlns="http://www.w3.org/2000/svg"
  viewBox="0 0 24 24"
  width="1em"
  height="1em"
  fill={FILLED[name] ? 'currentColor' : 'none'}
  stroke="currentColor"
  stroke-width="2"
  stroke-linecap="round"
  stroke-linejoin="round"
  aria-hidden="true"
  focusable="false"
>
  {#each PATHS[name] as d (d)}
    <path {d} />
  {/each}
</svg>

<style>
  .iwac-icon {
    display: inline-block;
    vertical-align: -0.125em;
    flex-shrink: 0;
  }
</style>
