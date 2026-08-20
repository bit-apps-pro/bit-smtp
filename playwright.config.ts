import { defineConfig, devices } from '@playwright/test'

/**
 * E2E config for the Bit SMTP admin SPA. Drives a real WordPress install.
 * Override via env: E2E_BASE_URL, E2E_WP_USER, E2E_WP_PASS, E2E_MAILPIT_URL.
 */
const BASE_URL = process.env.E2E_BASE_URL || 'http://wp-dev.io'

export default defineConfig({
  testDir: './tests/e2e',
  outputDir: './tests/e2e/.results',
  globalTeardown: './tests/e2e/global-teardown.ts',
  timeout: 60_000,
  expect: { timeout: 15_000 },
  fullyParallel: false,
  workers: 1,
  retries: process.env.CI ? 1 : 0,
  reporter: [['list'], ['html', { outputFolder: 'tests/e2e/.report', open: 'never' }]],
  use: {
    baseURL: BASE_URL,
    trace: 'retain-on-failure',
    screenshot: 'only-on-failure',
    video: 'retain-on-failure',
    actionTimeout: 15_000
  },
  projects: [
    { name: 'setup', testMatch: /auth\.setup\.ts/ },
    {
      name: 'chromium',
      use: { ...devices['Desktop Chrome'], storageState: 'tests/e2e/.auth/state.json' },
      dependencies: ['setup']
    }
  ]
})
