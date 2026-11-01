import { defineConfig, devices } from '@playwright/test';

/**
 * Real-browser tests for the BUILT client (asset/dist). Every request is
 * answered by page.route() from tests/browser/fixture.ts — the page shell,
 * the bundle, the scoped-key endpoint and a stand-in Typesense — under a
 * made-up origin, so no Omeka, PHP or Typesense is involved.
 * `npm run test:browser` builds first. Modelled on DRE Search's suite.
 */
export default defineConfig({
  testDir: 'tests/browser',
  fullyParallel: true,
  forbidOnly: Boolean(process.env.CI),
  retries: process.env.CI ? 1 : 0,
  reporter: process.env.CI ? [['list'], ['github']] : 'list',
  use: {
    baseURL: 'http://iwac.test',
    trace: 'retain-on-failure',
  },
  projects: [{ name: 'chromium', use: { ...devices['Desktop Chrome'] } }],
});
