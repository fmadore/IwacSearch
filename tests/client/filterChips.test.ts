import { describe, expect, it } from 'vitest';
import { deriveActiveChips } from '../../src/svelte/lib/filterChips';
import { createI18n } from '../../src/svelte/lib/i18n';

/**
 * S-14: the one derivation the sidebar, the summary strip and the empty state
 * all render, so they can never disagree about the scope on screen. The chip's
 * `value` is the raw token removal toggles; `displayValue` is what reads.
 */
describe('deriveActiveChips', () => {
  const fr = createI18n('fr');
  const en = createI18n('en');

  it('labels the field and the value, and keeps the raw token for removal', () => {
    const [chip] = deriveActiveChips({
      selected: { country_ss: ['Benin'] },
      yearRange: null,
      locale: 'fr',
      t: fr.t,
    });
    expect(chip).toEqual({
      field: 'country_ss',
      value: 'Benin',
      displayValue: 'Bénin',
      label: 'Pays',
      kind: 'facet',
    });
  });

  it('reads the subjectivity scale and the type discriminator as words', () => {
    const chips = deriveActiveChips({
      selected: { gpt_5_6_luna_subjectivite: ['1'], type_s: ['article'] },
      yearRange: null,
      locale: 'en',
      t: en.t,
    });
    expect(chips.map((c) => c.displayValue)).toEqual(['Very objective', 'News article']);
  });

  it('puts the single year chip last, across the slider bounds', () => {
    const chips = deriveActiveChips({
      selected: { country_ss: ['Niger'] },
      yearRange: { to: 2000 },
      locale: 'en',
      t: en.t,
      yearBounds: { min: 1912, max: 2026 },
    });
    expect(chips.map((c) => c.kind)).toEqual(['facet', 'year']);
    expect(chips[1]).toMatchObject({
      field: 'pub_year',
      displayValue: '1912 – 2000',
      label: 'Year',
    });
  });

  it('takes a per-field label override', () => {
    const [chip] = deriveActiveChips({
      selected: { newspaper_ss: ['Sidwaya'] },
      yearRange: null,
      locale: 'en',
      t: en.t,
      labels: { newspaper_ss: 'Title' },
    });
    expect(chip.label).toBe('Title');
  });

  it('is empty when nothing is selected', () => {
    expect(deriveActiveChips({ selected: {}, yearRange: null, locale: 'fr', t: fr.t })).toEqual([]);
  });
});
