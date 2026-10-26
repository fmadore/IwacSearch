import { describe, expect, it } from 'vitest';
import { mapPopupHtml, type MapPointProps } from '../../src/svelte/lib/mapPopup';

/**
 * The map's popup markup. Entities sharing coordinates are the case
 * clustering cannot resolve, so one popup has to list them all.
 */
const labels = {
  typeLabel: (type: string) => (type === 'Lieux' ? 'Places' : type),
  mentions: (n: number) => `${n} mentions`,
  here: (n: number) => `${n} entries at this location`,
};

const point = (
  id: number,
  title: string,
  frequency: number,
  url = `/item/${id}`,
): MapPointProps => ({
  id,
  title,
  entityType: 'Lieux',
  frequency,
  url,
});

describe('mapPopupHtml', () => {
  it('renders one entity as a linked title over its type and mentions', () => {
    const html = mapPopupHtml([point(1, 'Bobo-Dioulasso', 12)], labels);
    expect(html).toBe(
      '<div class="iwac-map__popup"><strong><a href="/item/1">Bobo-Dioulasso</a></strong>' +
        '<span>Places · 12 mentions</span></div>',
    );
  });

  it('lists several entities at one spot, most-mentioned first, under a count', () => {
    const doc = new DOMParser().parseFromString(
      mapPopupHtml(
        [point(1, 'Ouagadougou', 3), point(2, 'Mosquée centrale', 40), point(3, 'AEEMB', 9)],
        labels,
      ),
      'text/html',
    );
    expect(doc.querySelector('.iwac-map__popup-head')?.textContent).toBe(
      '3 entries at this location',
    );
    expect([...doc.querySelectorAll('li a')].map((a) => a.textContent)).toEqual([
      'Mosquée centrale',
      'AEEMB',
      'Ouagadougou',
    ]);
  });

  it('drops a feature MapLibre reports twice', () => {
    const html = mapPopupHtml([point(7, 'Niamey', 5), point(7, 'Niamey', 5)], labels);
    expect(html).not.toContain('<li>');
    expect(html.match(/Niamey/g)).toHaveLength(1);
  });

  it('escapes titles and URLs, and leaves an entity without a URL unlinked', () => {
    const html = mapPopupHtml(
      [point(1, '<b>X</b> & co', 1, ''), point(2, 'Y', 2, '/a?b="c"')],
      labels,
    );
    expect(html).toContain('<strong>&lt;b&gt;X&lt;/b&gt; &amp; co</strong>');
    expect(html).toContain('href="/a?b=&quot;c&quot;"');
  });
});
