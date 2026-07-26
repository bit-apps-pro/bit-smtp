import { type Page, expect, test } from '@playwright/test'
import { gotoSmtp } from './helpers'

// antd Switch inside a labelled Form.Item — toggle to the desired state.
async function setSwitch(page: Page, labelText: string, on: boolean): Promise<void> {
  const sw = page.locator('.ant-form-item').filter({ hasText: labelText }).getByRole('switch').first()
  await expect(sw).toBeVisible()
  const checked = (await sw.getAttribute('aria-checked')) === 'true'
  if (checked !== on) await sw.click()
}

// Click Save and wait for the settings-save request to complete (the success toast is transient).
async function save(page: Page): Promise<void> {
  const done = page
    .waitForResponse(r => r.request().method() === 'POST' && r.ok(), { timeout: 15_000 })
    .catch(() => null)
  await page.getByRole('button', { name: 'Save' }).click()
  await done
}

// Flow 3: configure failure-notification alerts, save, and confirm they persist across reload.
test.describe('Failure-notification alerts', () => {
  const recipient = `alerts-${Date.now()}@example.test`

  test.afterAll(async ({ browser }) => {
    // Restore: turn failure notifications back off (authenticated context).
    const context = await browser.newContext({ storageState: 'tests/e2e/.auth/state.json' })
    const page = await context.newPage()
    await gotoSmtp(page, '/notifications')
    await setSwitch(page, 'Webhook notification', false)
    await setSwitch(page, 'Failure notifications', false)
    await save(page)
    await context.close()
  })

  test('enables email alerts with a recipient and persists them', async ({ page }) => {
    await gotoSmtp(page, '/notifications')

    await setSwitch(page, 'Failure notifications', true)
    await setSwitch(page, 'Email notification', true)
    // Isolate the email channel: keep the webhook channel off so an empty webhook URL
    // (possible leftover site state) can't block the save.
    await setSwitch(page, 'Webhook notification', false)

    // Recipients is an antd tag-select: focus, type an address, commit with Enter.
    const recipients = page
      .locator('.ant-form-item')
      .filter({ hasText: 'Recipients' })
      .locator('.ant-select-selection-search-input')
    await recipients.click()
    await recipients.fill(recipient)
    await page.keyboard.press('Enter')

    await save(page)

    // Reload — the persisted settings come back (the authoritative check).
    await gotoSmtp(page, '/notifications')
    const master = page
      .locator('.ant-form-item')
      .filter({ hasText: 'Failure notifications' })
      .getByRole('switch')
      .first()
    await expect(master).toHaveAttribute('aria-checked', 'true')
    await expect(page.getByText(recipient).first()).toBeVisible()
  })
})
