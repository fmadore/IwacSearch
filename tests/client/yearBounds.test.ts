import { describe, expect, it } from 'vitest';
import {
  DEFAULT_YEAR_MIN,
  clampYearRange,
  defaultYearMax,
  resolveYearBounds,
} from '../../src/svelte/lib/yearBounds';
import { deriveActiveChips } from '../../src/svelte/lib/filterChips';
import { translate } from '../../src/svelte/lib/i18n';

/**
 * The year slider's bounds (S-02). It was hardcoded to 1960–2025 while the
 * corpus runs from references of 1912 to articles of 2026, so neither end was
 * selectable and `?date.from=2026` drew "2026 – 2025" with a thumb off the
 * track. Live span on 2026-10-08: 1912–2026 over everything, 1961–2026 on the
 * primary sources.
 */

const NOW = new Date('2026-10-08T12:00:00Z');

describe('resolveYearBounds', () => {
  it('is the data span once it is known', () => {
    expect(resolveYearBounds({ min: 1912, max: 2026 }, [], { from: 1800 }, NOW)).toEqual({
      min: 1912,
      max: 2026,
    });
  });

  it('falls back to 1960 and the CURRENT year, never a stale constant', () => {
    expect(resolveYearBounds(null, [], null, NOW)).toEqual({ min: DEFAULT_YEAR_MIN, max: 2026 });
    expect(defaultYearMax(new Date('2031-01-01T00:00:00Z'))).toBe(2031);
  });

  it('widens the fallback to every bar on screen and both requested ends', () => {
    const buckets = [
      { year: 1912, count: 1 },
      { year: 2026, count: 387 },
    ];
    expect(resolveYearBounds(null, buckets, null, NOW)).toEqual({ min: 1912, max: 2026 });
    expect(resolveYearBounds(null, [], { from: 1950, to: 2030 }, NOW)).toEqual({
      min: 1950,
      max: 2030,
    });
  });
});

describe('clampYearRange', () => {
  const span = { min: 1912, max: 2026 };

  it('keeps a range already inside the span', () => {
    expect(clampYearRange({ from: 1990, to: 2010 }, span)).toEqual({ from: 1990, to: 2010 });
    // The URL from the finding: now a one-year window at the end of the track.
    expect(clampYearRange({ from: 2026 }, span)).toEqual({ from: 2026 });
  });

  it('drops an end at or beyond its own bound — that side is open anyway', () => {
    expect(clampYearRange({ from: 1850, to: 1990 }, span)).toEqual({ to: 1990 });
    expect(clampYearRange({ from: 1990, to: 2100 }, span)).toEqual({ from: 1990 });
    expect(clampYearRange({ from: 1800, to: 2100 }, span)).toBeNull();
  });

  it('pulls an end beyond the OTHER bound back onto the track', () => {
    expect(clampYearRange({ from: 2050 }, span)).toEqual({ from: 2026 });
    expect(clampYearRange({ to: 1850 }, span)).toEqual({ to: 1912 });
  });

  it('swaps reversed ends', () => {
    expect(clampYearRange({ from: 2010, to: 1990 }, span)).toEqual({ from: 1990, to: 2010 });
  });

  it('passes null through', () => {
    expect(clampYearRange(null, span)).toBeNull();
  });
});

describe('the year chip', () => {
  const t = (key: string, vars?: Record<string, string | number>) => translate('en', key, vars);

  it('names the same ends the slider shows', () => {
    const chips = deriveActiveChips({
      selected: {},
      yearRange: { from: 1990 },
      locale: 'en',
      t,
      yearBounds: { min: 1912, max: 2026 },
    });
    expect(chips).toHaveLength(1);
    expect(chips[0].displayValue).toBe('1990 – 2026');
  });
});
