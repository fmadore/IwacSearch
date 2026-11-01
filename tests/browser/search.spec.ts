import AxeBuilder from '@axe-core/playwright';
import { expect, test, type Locator, type Page } from '@playwright/test';
import { serve } from './fixture';

/**
 * The BUILT bundle (asset/dist) in a real browser, against the stubbed page
 * and Typesense of ./fixture.ts. What the unit suite cannot see: the mount
 * contract, real focus and keyboard handling, layout at a phone's width,
 * the theme's two palettes and what axe makes of the rendered markup.
 */

const SEARCH = '/s/westafrica/search';
const EVERYTHING = '/s/westafrica/search/everything';
const PAGE = '/s/westafrica/page/collection';

/** axe violations a visitor would hit: serious and critical only. */
async function seriousViolations(page: Page): Promise<string[]> {
  const results = await new AxeBuilder({ page }).include('main').analyze();
  return results.violations
    .filter((v) => v.impact === 'serious' || v.impact === 'critical')
    .map((v) => `${v.id}: ${v.nodes.map((n) => n.target.join(' ')).join(', ')}`);
}

/** Flip the theme the way its toggle does (body[data-theme]) and let transitions settle. */
async function setTheme(page: Page, mode: 'light' | 'dark'): Promise<void> {
  await page.evaluate((value) => {
    document.body.dataset.theme = value;
  }, mode);
  // Controls ease their colours; an axe scan inside that window measures a
  // half-switched contrast pair.
  await page.waitForFunction(() =>
    document.getAnimations().every((animation) => animation.playState !== 'running'),
  );
}

async function horizontalOverflow(page: Page): Promise<number> {
  return page.evaluate(
    () => document.documentElement.scrollWidth - document.documentElement.clientWidth,
  );
}

const cards = (page: Page): Locator => page.getByRole('article');
const searchBox = (page: Page): Locator => page.getByRole('combobox', { name: 'Search the IWAC…' });
/** The surface's own live region (the typeahead has a second one). */
const announcer = (page: Page): Locator => page.locator('.iwac-search__sr-only[role="status"]');

test.describe('the /search shell', () => {
  test('mounts from the built bundle and fetches its first page', async ({ page }) => {
    const stub = await serve(page, 'search');
    await page.goto(SEARCH);
    await expect(cards(page)).toHaveCount(3);
    const card = cards(page).filter({ hasText: 'Le Ramadan à Ouagadougou' });
    await expect(
      card.getByRole('heading', { level: 3, name: 'Le Ramadan à Ouagadougou' }),
    ).toBeVisible();
    // The indexed French-site link is rewritten onto the site being read.
    await expect(card.getByRole('link', { name: 'Le Ramadan à Ouagadougou' })).toHaveAttribute(
      'href',
      '/s/westafrica/item/101',
    );
    await expect(page.getByRole('heading', { level: 2, name: 'Filters' })).toBeVisible();
    await expect(page.locator('[data-iwac-search-root]')).toHaveAttribute('data-iwac-mounted', '1');
    expect(stub.searches()).toHaveLength(1);
    await expect(announcer(page)).toHaveText(/3 results/);
  });

  test('adopts the server-rendered first page instead of searching for it', async ({ page }) => {
    const stub = await serve(page, 'search-ssr');
    await page.goto(SEARCH);
    await expect(cards(page)).toHaveCount(3);
    // The snapshot carries no histogram, so that alone is still asked for.
    await expect.poll(() => stub.requests.some((r) => r.kind === 'aux')).toBe(true);
    expect(stub.searches()).toHaveLength(0);
    // Date order, as the server rendered it.
    await expect(cards(page).first()).toContainText('Les confréries soufies au Niger');
  });

  test('a shared link is searched as asked, not answered with the snapshot', async ({ page }) => {
    const stub = await serve(page, 'search-ssr');
    await page.goto(`${SEARCH}?q=Tabaski`);
    await expect(cards(page)).toHaveCount(1);
    await expect(cards(page)).toContainText('La Tabaski à Niamey');
    expect(stub.searches().at(-1)?.q).toBe('Tabaski');
    await expect(searchBox(page)).toHaveValue('Tabaski');
  });

  test('typing searches as you type, and the typeahead follows the keys', async ({ page }) => {
    const stub = await serve(page, 'search');
    await page.goto(SEARCH);
    await expect(cards(page)).toHaveCount(3);

    const box = searchBox(page);
    await box.pressSequentially('Rama');
    const listbox = page.getByRole('listbox', { name: 'Suggestions' });
    await expect(listbox.getByRole('option', { name: 'Search for “Rama”' })).toBeVisible();
    await expect(listbox.getByRole('option', { name: 'Le Ramadan à Ouagadougou' })).toBeVisible();
    await expect(listbox.getByRole('option', { name: /^Ramadan/ })).toBeVisible();
    await expect(box).toHaveAttribute('aria-expanded', 'true');
    // The results followed the box without an Enter.
    await expect(cards(page)).toHaveCount(1);
    expect(stub.searches().at(-1)?.q).toBe('Rama');

    // The search action is armed first; the arrows move the active option.
    const options = listbox.getByRole('option');
    await expect(options.first()).toHaveAttribute('aria-selected', 'true');
    await box.press('ArrowDown');
    await expect(options.nth(1)).toHaveAttribute('aria-selected', 'true');
    await expect(box).toHaveAttribute('aria-activedescendant', /.+/);
    await box.press('Escape');
    await expect(listbox).toBeHidden();
    await expect(box).toHaveAttribute('aria-expanded', 'false');
  });

  test('a typeahead entity becomes a filter and clears the box', async ({ page }) => {
    const stub = await serve(page, 'search');
    await page.goto(SEARCH);
    await searchBox(page).pressSequentially('Tabas');
    await page.getByRole('option', { name: /^Tabaski/ }).click();
    await expect(searchBox(page)).toHaveValue('');
    await expect(cards(page)).toHaveCount(1);
    await expect(cards(page)).toContainText('La Tabaski à Niamey');
    expect(stub.searches().at(-1)?.filter_by).toBe('topics_ss:=[`Tabaski`]');
    expect(new URL(page.url()).searchParams.getAll('f.topics_ss')).toEqual(['Tabaski']);
  });

  test('an article suggestion opens the record', async ({ page }) => {
    await serve(page, 'search');
    await page.goto(SEARCH);
    await searchBox(page).pressSequentially('Tabas');
    await page.getByRole('option', { name: 'La Tabaski à Niamey' }).click();
    await expect(page).toHaveURL(/\/s\/westafrica\/item\/102$/);
  });

  test('a facet toggles a filter, and so do the chips on a result', async ({ page }) => {
    const stub = await serve(page, 'search');
    await page.goto(SEARCH);
    await expect(cards(page)).toHaveCount(3);

    await page.getByRole('checkbox', { name: /^Niger/ }).check();
    await expect(cards(page)).toHaveCount(2);
    expect(stub.searches().at(-1)?.filter_by).toBe('country_ss:=[`Niger`]');
    expect(new URL(page.url()).searchParams.getAll('f.country_ss')).toEqual(['Niger']);

    // A card's country chip is pressed while its filter is on: pressing it
    // takes the filter off again.
    const tabaski = cards(page).filter({ hasText: 'La Tabaski à Niamey' });
    await tabaski.getByRole('button', { name: /^Remove filter .*: Niger$/ }).click();
    await expect(cards(page)).toHaveCount(3);
    await expect(page.getByRole('checkbox', { name: /^Niger/ })).not.toBeChecked();

    // …and an unpressed chip puts one on.
    const ramadan = cards(page).filter({ hasText: 'Le Ramadan à Ouagadougou' });
    await ramadan.getByRole('button', { name: /^Filter by .*: Sidwaya$/ }).click();
    await expect(cards(page)).toHaveCount(1);
    await expect(page.getByRole('checkbox', { name: /^Sidwaya/ })).toBeChecked();
    await expect(
      cards(page).getByRole('button', { name: /^Remove filter .*: Sidwaya$/ }),
    ).toHaveAttribute('aria-pressed', 'true');

    await page.getByRole('button', { name: 'Clear all', exact: true }).first().click();
    await expect(cards(page)).toHaveCount(3);
  });

  test('a query that matches nothing says so, and offers the entity it nearly named', async ({
    page,
  }) => {
    await serve(page, 'search');
    await page.goto(SEARCH);
    const box = searchBox(page);
    await box.fill('tidjan');
    await box.press('Enter');
    await expect(page.getByText('No results.', { exact: true })).toBeVisible();
    await expect(cards(page)).toHaveCount(0);
    await expect(announcer(page)).not.toHaveText('');

    await expect(page.getByText('Did you mean:')).toBeVisible();
    await page.getByRole('button', { name: /Tidjaniyya/ }).click();
    await expect(cards(page)).toHaveCount(1);
    await expect(cards(page)).toContainText('Les confréries soufies au Niger');
    await expect(page.getByText('Did you mean:')).toBeHidden();
  });

  test('a failed search says so in the reader’s words, and Retry recovers', async ({ page }) => {
    const stub = await serve(page, 'search');
    const logged: string[] = [];
    page.on('console', (message) => {
      if (message.type() === 'error') logged.push(message.text());
    });
    await page.goto(SEARCH);
    await expect(cards(page)).toHaveCount(3);

    stub.failSearches = 1;
    const box = searchBox(page);
    await box.fill('Tabaski');
    await box.press('Enter');
    const alert = page.getByRole('alert').filter({ hasText: 'Search unavailable.' });
    await expect(alert).toBeVisible();
    await expect(cards(page)).toHaveCount(0);
    // The transport detail is for the console, never the page.
    await expect(page.locator('main')).not.toContainText('Typesense');
    await expect(page.locator('main')).not.toContainText('500');
    expect(logged.join('\n')).toContain('Typesense timed out (fixture)');

    await alert.getByRole('button', { name: 'Retry' }).click();
    await expect(alert).toBeHidden();
    await expect(cards(page)).toHaveCount(1);
  });

  test('the typeahead says when nothing matches, when it failed, and recovers', async ({
    page,
  }) => {
    const stub = await serve(page, 'search');
    await page.goto(SEARCH);
    const box = searchBox(page);
    const status = page.locator('.iwac-suggest__sr[role="status"]');

    await box.pressSequentially('zzz');
    await expect(status).toHaveText('No matches.');

    stub.failSuggests = 1;
    await box.fill('');
    await box.pressSequentially('Tab');
    await expect(status).toHaveText('Suggestions unavailable.');

    await box.pressSequentially('a');
    await expect(status).toHaveText('');
    await expect(page.getByRole('option', { name: 'La Tabaski à Niamey' })).toBeVisible();
  });

  test('follows the theme into dark mode', async ({ page }) => {
    await serve(page, 'search');
    await page.goto(SEARCH);
    await expect(cards(page).first()).toBeVisible();
    const ink = (): Promise<string> =>
      cards(page)
        .first()
        .getByRole('heading')
        .evaluate((el) => getComputedStyle(el).color);
    const light = await ink();
    await setTheme(page, 'dark');
    expect(await ink()).not.toBe(light);
  });

  test('has no serious or critical axe violations, in light or dark', async ({ page }) => {
    await serve(page, 'search');
    await page.goto(SEARCH);
    await expect(cards(page)).toHaveCount(3);
    expect(await seriousViolations(page)).toEqual([]);
    await setTheme(page, 'dark');
    expect(await seriousViolations(page)).toEqual([]);
  });

  test('does not scroll sideways at 320px, and stays accessible there', async ({ page }) => {
    await page.setViewportSize({ width: 320, height: 800 });
    await serve(page, 'search');
    await page.goto(SEARCH);
    await expect(cards(page).first()).toBeVisible();
    expect(await horizontalOverflow(page)).toBeLessThanOrEqual(0);
    // The phone layout drops labels and moves the facets into a drawer: the
    // view toggle's radios once lost their names here.
    expect(await seriousViolations(page)).toEqual([]);
  });
});

test.describe('the filter drawer on a phone', () => {
  test.use({ viewport: { width: 375, height: 800 } });

  test('opens as a dialog, keeps focus inside, closes on Escape', async ({ page }) => {
    await serve(page, 'search');
    await page.goto(SEARCH);
    await expect(cards(page).first()).toBeVisible();
    // No sidebar on a phone: the facets live behind the trigger.
    await expect(page.locator('.iwac-search__facets-inline')).toHaveCount(0);

    const trigger = page.getByRole('button', { name: 'Open filters' });
    await expect(trigger).toHaveAttribute('aria-expanded', 'false');
    await trigger.click();
    const dialog = page.getByRole('dialog', { name: 'Filters' });
    await expect(dialog).toBeVisible();
    await expect(trigger).toHaveAttribute('aria-expanded', 'true');

    const focusInside = (): Promise<boolean> =>
      dialog.evaluate((el) => el.contains(document.activeElement));
    expect(await focusInside()).toBe(true);
    // Walk forward past the end, then backward past the start: the trap wraps.
    const stops = await dialog.evaluate(
      (el) =>
        el.querySelectorAll('button, input, a[href], select, [tabindex]:not([tabindex="-1"])')
          .length,
    );
    for (let i = 0; i < stops + 2; i++) {
      await page.keyboard.press('Tab');
      expect(await focusInside()).toBe(true);
    }
    for (let i = 0; i < 3; i++) {
      await page.keyboard.press('Shift+Tab');
      expect(await focusInside()).toBe(true);
    }

    // A filter picked in the drawer applies behind it.
    await dialog.getByRole('checkbox', { name: /^Niger/ }).check();
    await expect(cards(page)).toHaveCount(2);

    await page.keyboard.press('Escape');
    await expect(dialog).toBeHidden();
    await expect(trigger).toBeFocused();
    await expect(trigger).toHaveAttribute('aria-expanded', 'false');
  });

  test('has no serious or critical axe violations with the drawer open', async ({ page }) => {
    await serve(page, 'search');
    await page.goto(SEARCH);
    await expect(cards(page).first()).toBeVisible();
    await page.getByRole('button', { name: 'Open filters' }).click();
    await expect(page.getByRole('dialog', { name: 'Filters' })).toBeVisible();
    await page.waitForFunction(() =>
      document.getAnimations().every((animation) => animation.playState !== 'running'),
    );
    expect(await seriousViolations(page)).toEqual([]);
  });
});

test.describe('a page block', () => {
  test('nests its headings under the block title', async ({ page }) => {
    const stub = await serve(page, 'titled-block');
    await page.goto(PAGE);
    await expect(cards(page)).toHaveCount(3);
    // This block came with its first page: adopted, not searched again.
    await expect.poll(() => stub.requests.some((r) => r.kind === 'aux')).toBe(true);
    expect(stub.searches()).toHaveLength(0);
    await expect(page.getByRole('heading', { level: 1 })).toHaveCount(1);
    await expect(page.getByRole('heading', { level: 2 })).toHaveText(['Find articles']);
    await expect(page.getByRole('heading', { level: 3, name: 'Filters' })).toBeVisible();
    await expect(page.getByRole('heading', { level: 3, name: 'Results' })).toBeAttached();
    await expect(
      page.getByRole('heading', { level: 4, name: 'Le Ramadan à Ouagadougou' }),
    ).toBeVisible();
    await expect(page.getByRole('heading', { level: 4, name: /^Country/ })).toBeVisible();
  });

  test('without a title, its headings sit under the page title', async ({ page }) => {
    const stub = await serve(page, 'block');
    await page.goto(PAGE);
    await expect(cards(page)).toHaveCount(3);
    expect(stub.searches()).toHaveLength(1);
    await expect(page.getByRole('heading', { level: 2, name: 'Filters' })).toBeVisible();
    await expect(
      page.getByRole('heading', { level: 3, name: 'Le Ramadan à Ouagadougou' }),
    ).toBeVisible();
  });

  test('keeps its state under its own prefix, beside the host page’s', async ({ page }) => {
    const stub = await serve(page, 'block');
    await page.goto(`${PAGE}?page=3`);
    await expect(cards(page)).toHaveCount(3);
    // The host's own ?page= is not the block's.
    expect(stub.searches().at(-1)?.page).toBe(1);
    await page.getByRole('checkbox', { name: /^Niger/ }).check();
    await expect(cards(page)).toHaveCount(2);
    const params = new URL(page.url()).searchParams;
    expect(params.getAll('b42.f.country_ss')).toEqual(['Niger']);
    expect(params.get('page')).toBe('3');
  });

  test('has no serious or critical axe violations', async ({ page }) => {
    await serve(page, 'titled-block');
    await page.goto(PAGE);
    await expect(cards(page)).toHaveCount(3);
    expect(await seriousViolations(page)).toEqual([]);
  });
});

test.describe('the federated page', () => {
  test('tabs: arrows move focus, Enter selects, the panel follows', async ({ page }) => {
    await serve(page, 'federated');
    await page.goto(EVERYTHING);
    const content = page.getByRole('tab', { name: /^Content/ });
    await expect(content).toHaveAttribute('aria-selected', 'true');
    await expect(cards(page)).toHaveCount(3);
    const panel = page.getByRole('tabpanel');
    await expect(panel).toHaveAttribute('aria-labelledby', 'iwac-fed-tab-content');
    // The badges count each collection.
    await expect(page.getByRole('tab', { name: /^Entities\s*3$/ })).toBeVisible();

    await content.focus();
    await page.keyboard.press('ArrowRight');
    const entities = page.getByRole('tab', { name: /^Entities/ });
    await expect(entities).toBeFocused();
    // Manual activation: focus alone neither switches nor searches.
    await expect(entities).toHaveAttribute('aria-selected', 'false');

    await page.keyboard.press('Enter');
    await expect(entities).toHaveAttribute('aria-selected', 'true');
    await expect(panel).toHaveAttribute('aria-labelledby', 'iwac-fed-tab-entities');
    await expect(cards(page).first()).toContainText('Ouagadougou');

    // Home → the merged ranking over both collections.
    await page.keyboard.press('Home');
    await page.keyboard.press('Enter');
    await expect(page.getByRole('tab', { name: /^All/ })).toHaveAttribute('aria-selected', 'true');
    await expect(cards(page)).toHaveCount(6);
    await expect(page.getByRole('heading', { level: 2, name: 'Results' })).toBeAttached();
    expect(new URL(page.url()).searchParams.get('tab')).toBe('all');
  });

  test('a failed merged search offers Retry', async ({ page }) => {
    const stub = await serve(page, 'federated');
    await page.goto(`${EVERYTHING}?tab=all`);
    await expect(cards(page)).toHaveCount(6);
    stub.failSearches = 1;
    await page.getByRole('searchbox', { name: 'Search the whole collection…' }).fill('Niamey');
    const alert = page.getByRole('alert').filter({ hasText: 'Search unavailable.' });
    await expect(alert).toBeVisible();
    await alert.getByRole('button', { name: 'Retry' }).click();
    await expect(alert).toBeHidden();
    await expect(cards(page).first()).toContainText('Niamey');
  });

  test('has no serious or critical axe violations, in light or dark', async ({ page }) => {
    await serve(page, 'federated');
    await page.goto(EVERYTHING);
    await expect(cards(page)).toHaveCount(3);
    expect(await seriousViolations(page)).toEqual([]);
    await setTheme(page, 'dark');
    expect(await seriousViolations(page)).toEqual([]);
  });

  test('does not scroll sideways at 320px', async ({ page }) => {
    await page.setViewportSize({ width: 320, height: 800 });
    await serve(page, 'federated');
    await page.goto(EVERYTHING);
    await expect(cards(page).first()).toBeVisible();
    expect(await horizontalOverflow(page)).toBeLessThanOrEqual(0);
  });
});

test.describe('the entity map', () => {
  const canvas = (page: Page): Locator => page.locator('.iwac-map__canvas canvas');

  test('plots the geo-tagged entities, and says when there are none', async ({ page }) => {
    await serve(page, 'entities');
    await page.goto(SEARCH);
    await expect(cards(page)).toHaveCount(3);
    await page.getByRole('radio', { name: 'Map' }).click();
    await expect(page.getByRole('region', { name: 'Map of places' })).toBeVisible();
    await expect(canvas(page)).toHaveAttribute('data-points', '2');

    await searchBox(page).fill('zzz');
    await expect(page.getByText('No geo-located places in these results.')).toBeVisible();
  });

  test('a failed fetch says so — not “no places” — and Retry recovers', async ({ page }) => {
    const stub = await serve(page, 'entities');
    await page.goto(SEARCH);
    await expect(cards(page)).toHaveCount(3);
    stub.failMaps = 1;
    await page.getByRole('radio', { name: 'Map' }).click();
    const alert = page
      .getByRole('alert')
      .filter({ hasText: 'The places for these results could not be loaded.' });
    await expect(alert).toBeVisible();
    await expect(page.getByText('No geo-located places in these results.')).toBeHidden();
    await alert.getByRole('button', { name: 'Retry' }).click();
    await expect(alert).toBeHidden();
    await expect(canvas(page)).toHaveAttribute('data-points', '2');
  });
});
