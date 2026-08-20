import { expect, test } from '@playwright/test'
import { gotoSmtp } from './helpers'

// Flow 4: the delivery-webhook panel on a webhook-capable connection (SendGrid).
// Asserts the metadata-driven UI: pasteable webhook URL, verification badge, and the
// "Create webhook in <provider>" button that only renders for supports_webhook_provisioning.
test.describe('Delivery webhook UI', () => {
  test('SendGrid connection shows the webhook URL, status badge and provisioning button', async ({
    page
  }) => {
    await gotoSmtp(page, '/')

    // Open the SendGrid connection editor. The seeded connection name starts with "E2E SendGrid".
    const row = page
      .locator('div')
      .filter({ has: page.getByText(/^E2E SendGrid/) })
      .filter({ has: page.getByRole('button', { name: /edit/i }) })
      .last()
    await row.getByRole('button', { name: /edit/i }).click()

    // Editor open; ensure delivery webhook is enabled so the panel is shown.
    await expect(page.getByRole('button', { name: 'Save' })).toBeVisible()
    const webhookSwitch = page
      .locator('.ant-form-item')
      .filter({ hasText: 'Enable delivery webhook' })
      .getByRole('switch')
      .first()
    if ((await webhookSwitch.getAttribute('aria-checked')) !== 'true') await webhookSwitch.click()

    // Webhook panel assertions.
    await expect(page.getByText(/\/bit-smtp\//)).toBeVisible() // pasteable webhook URL
    await expect(page.getByText(/Verified — receiving events|Waiting for first event/)).toBeVisible()
    await expect(page.getByRole('button', { name: 'Create webhook in SendGrid' })).toBeVisible()
  })
})
