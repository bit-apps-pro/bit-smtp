import { expect, test } from '@playwright/test'
import { fillOtherSmtpMailpit, gotoSmtp, uniqueName } from './helpers'

// Flow 1: create a connection (Add connection -> pick provider -> fill -> save -> appears in list).
// Created connections are removed by the suite's global teardown.
test.describe('Connection setup', () => {
  const name = uniqueName('E2E SMTP')

  test('adds an Other SMTP connection and lists it', async ({ page }) => {
    await gotoSmtp(page, '/')
    await fillOtherSmtpMailpit(page, name, 'e2e-sender@example.test')
    await page.getByRole('button', { name: 'Save' }).click()

    // Save returns to the connections list, where the new connection now appears.
    await expect(page.getByRole('heading', { name: 'Connections' })).toBeVisible()
    await expect(page.getByText(name)).toBeVisible()
  })
})
