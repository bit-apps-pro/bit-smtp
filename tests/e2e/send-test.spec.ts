import { expect, test } from '@playwright/test'
import { fillOtherSmtpMailpit, gotoSmtp, mailpit, uniqueName } from './helpers'

// Flow 2: send a test through an SMTP->mailpit connection and confirm delivery.
// Uses the connection editor's "Test Connection" (sends to the From address) so the
// site's default connection is never mutated. Delivery is confirmed via the Mailpit API.
test.describe('Send test', () => {
  test('Test Connection on an SMTP->mailpit connection delivers to the inbox', async ({
    page,
    request
  }) => {
    const stamp = Date.now()
    const fromEmail = `e2e-send-${stamp}@example.test`
    const name = uniqueName('E2E Send')

    await mailpit.deleteAll(request)

    await gotoSmtp(page, '/')
    await fillOtherSmtpMailpit(page, name, fromEmail)

    // Send a live test through this connection (delivers to the From address).
    await page.getByRole('button', { name: 'Test Connection' }).click()
    await expect(
      page.getByText(/Connection test successful|Delivered|Accepted by provider/i)
    ).toBeVisible()

    // Confirm the message actually reached Mailpit.
    await expect(async () => {
      const found = await mailpit.search(request, `to:${fromEmail}`)
      expect(found.messages_count).toBeGreaterThan(0)
    }).toPass({ timeout: 15_000 })
  })
})
