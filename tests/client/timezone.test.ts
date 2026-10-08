import { describe, expect, it } from 'vitest';

/**
 * The guard on vitest.config.ts's TZ: the date tests only prove anything if
 * they run somewhere midnight UTC is still YESTERDAY. If a platform ever
 * ignores the variable, this fails instead of every date test passing
 * vacuously.
 */
describe('test environment', () => {
  it('runs west of Greenwich', () => {
    expect(Intl.DateTimeFormat().resolvedOptions().timeZone).toBe('America/New_York');
    // 1989-11-04T00:00:00Z is still 3 November in New York.
    expect(new Date(626140800 * 1000).getDate()).toBe(3);
  });
});
