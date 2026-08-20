import { expect, test as setup } from '@playwright/test'
import fs from 'node:fs'

const AUTH_FILE = 'tests/e2e/.auth/state.json'
const USER = process.env.E2E_WP_USER || 'codex-e2e'
const PASS = process.env.E2E_WP_PASS || 'E2ePass!2026'

// Log in to WordPress once and persist the session for every spec.
setup('authenticate as WP admin', async ({ page }) => {
  await page.goto('/wp-login.php')
  await page.locator('#user_login').fill(USER)
  await page.locator('#user_pass').fill(PASS)
  await page.locator('#wp-submit').click()
  await page.waitForURL('**/wp-admin/**')
  await expect(page.locator('#wpadminbar')).toBeVisible()

  fs.mkdirSync('tests/e2e/.auth', { recursive: true })
  await page.context().storageState({ path: AUTH_FILE })
})
