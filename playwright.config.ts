import { defineConfig, devices } from '@playwright/test';

/**
 * End-to-end suite — see docs/briefs/e2e-playwright-coverage.md.
 *
 * Locally the specs run against the Sail app that is already up
 * (`http://localhost`, the port compose.yaml publishes). CI sets
 * E2E_START_SERVER=1 and lets Playwright boot `php artisan serve` instead.
 */
const baseURL = process.env.E2E_BASE_URL ?? 'http://localhost';
const startServer = process.env.E2E_START_SERVER === '1';

export default defineConfig({
    testDir: './tests/e2e',
    globalSetup: './tests/e2e/global-setup.ts',
    fullyParallel: true,
    forbidOnly: !!process.env.CI,
    retries: process.env.CI ? 1 : 0,
    // Four locally: a dev-mode Vite server compiles on demand, and seven
    // browsers asking it for the same pages at once is what makes assertions
    // time out rather than fail honestly.
    workers: process.env.CI ? 2 : 4,
    reporter: process.env.CI
        ? [['list'], ['html', { open: 'never' }]]
        : [['list']],
    timeout: 60_000,
    // Locally the app is usually served by a dev-mode Vite server that compiles
    // pages on demand; CI serves a build, where a slow assertion means a real
    // problem rather than a cold page.
    expect: { timeout: process.env.CI ? 10_000 : 30_000 },

    use: {
        baseURL,
        // The codebase writes `data-test`, not `data-testid`.
        testIdAttribute: 'data-test',
        trace: 'on-first-retry',
        screenshot: 'only-on-failure',
        video: 'retain-on-failure',
    },

    projects: [{ name: 'chromium', use: { ...devices['Desktop Chrome'] } }],

    webServer: startServer
        ? {
              command: 'php artisan serve --host=127.0.0.1 --port=8000',
              // PHP's built-in server answers one request at a time unless it is
              // told to fork; with parallel workers that serialises the suite.
              env: { PHP_CLI_SERVER_WORKERS: '4' },
              url: baseURL,
              reuseExistingServer: false,
              timeout: 120_000,
              stdout: 'pipe',
              stderr: 'pipe',
          }
        : undefined,
});
