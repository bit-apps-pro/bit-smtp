import { type Page, expect, test } from '@playwright/test'
import { gotoSmtp } from './helpers'

const MASK_SENTINEL = '********'
const TEST_SLACK_WEBHOOK_URL = 'https://hooks.slack.com/services/T00000000/B00000000/e2e-secret'
const TEST_TELEGRAM_BOT_TOKEN = '123456:abcdefghijklmnopqrstuvwxyz'
const TEST_TELEGRAM_CHAT_ID = '-1001234567890'
const NOTIFICATION_TEST_ENDPOINT = '/wp-json/bit-smtp/v1/mail/notifications/test'

interface AlertState {
  enabled: boolean
  emailEnabled: boolean
  webhookEnabled: boolean
  slackEnabled: boolean
  telegramEnabled: boolean
  recipients: string[]
  slackWebhookUrl: string
  telegramBotToken: string
  telegramChatId: string
}

// antd Switch inside a labelled Form.Item — toggle to the desired state.
async function setSwitch(page: Page, labelText: string, on: boolean): Promise<void> {
  const sw = page.locator('.ant-form-item').filter({ hasText: labelText }).getByRole('switch').first()
  await expect(sw).toBeVisible()
  const checked = (await sw.getAttribute('aria-checked')) === 'true'
  if (checked !== on) await sw.click()
}

function formItem(page: Page, labelText: string) {
  return page.locator('.ant-form-item').filter({ hasText: labelText }).first()
}

async function isSwitchOn(page: Page, labelText: string): Promise<boolean> {
  return (await formItem(page, labelText).getByRole('switch').getAttribute('aria-checked')) === 'true'
}

async function readAlertState(page: Page): Promise<AlertState> {
  return {
    enabled: await isSwitchOn(page, 'Failure notifications'),
    emailEnabled: await isSwitchOn(page, 'Email notification'),
    webhookEnabled: await isSwitchOn(page, 'Webhook notification'),
    slackEnabled: await isSwitchOn(page, 'Slack notification'),
    telegramEnabled: await isSwitchOn(page, 'Telegram notification'),
    recipients: await formItem(page, 'Recipients')
      .locator('.ant-select-selection-item-content')
      .allTextContents(),
    slackWebhookUrl: await page.getByLabel('Slack webhook URL').inputValue(),
    telegramBotToken: await page.getByLabel('Telegram bot token').inputValue(),
    telegramChatId: await page.getByLabel('Telegram chat ID').inputValue()
  }
}

async function replaceRecipients(page: Page, recipients: string[]): Promise<void> {
  const item = formItem(page, 'Recipients')
  const removeButtons = item.locator('.ant-select-selection-item-remove')

  while (await removeButtons.count()) {
    await removeButtons.first().click()
  }

  const input = item.locator('.ant-select-selection-search-input')
  for (const recipient of recipients) {
    await input.fill(recipient)
    await page.keyboard.press('Enter')
  }
}

// Click Save and wait for the settings-save request to complete (the success toast is transient).
async function save(page: Page): Promise<void> {
  const done = page.waitForResponse(
    response =>
      response.request().method() === 'POST' &&
      new URL(response.url()).pathname.endsWith('/mail/settings/save') &&
      response.ok(),
    { timeout: 15_000 }
  )
  await page.getByRole('button', { name: 'Save' }).click()
  await done
}

async function restoreAlertState(page: Page, state: AlertState): Promise<void> {
  await gotoSmtp(page, '/notifications')

  // Turn controls on temporarily so values can be restored before their original switches are reapplied.
  await setSwitch(page, 'Failure notifications', true)
  await setSwitch(page, 'Email notification', true)
  await setSwitch(page, 'Slack notification', true)
  await setSwitch(page, 'Telegram notification', true)

  await replaceRecipients(page, state.recipients)

  // Secrets already present at the start are masked and therefore left untouched. Only the test-only
  // values introduced for previously blank fields need to be cleared.
  if (!state.slackWebhookUrl) await page.getByLabel('Slack webhook URL').fill('')
  if (!state.telegramBotToken) await page.getByLabel('Telegram bot token').fill('')
  if (!state.telegramChatId) await page.getByLabel('Telegram chat ID').fill('')

  await setSwitch(page, 'Email notification', state.emailEnabled)
  await setSwitch(page, 'Webhook notification', state.webhookEnabled)
  await setSwitch(page, 'Slack notification', state.slackEnabled)
  await setSwitch(page, 'Telegram notification', state.telegramEnabled)
  await setSwitch(page, 'Failure notifications', state.enabled)
  await save(page)
}

async function testNotificationChannel(page: Page, channel: 'slack' | 'telegram'): Promise<void> {
  const request = page.waitForRequest(candidate => {
    if (candidate.method() !== 'POST' || !candidate.url().endsWith(NOTIFICATION_TEST_ENDPOINT)) {
      return false
    }

    return JSON.parse(candidate.postData() || '{}').channel === channel
  })

  await page
    .getByRole('button', { name: `Test ${channel[0].toUpperCase()}${channel.slice(1)} notification` })
    .click()
  await request
  await expect(page.getByText('Test notification sent')).toBeVisible()
}

// Flow 3: configure failure-notification alerts, save, and confirm they persist across reload.
test.describe('Failure-notification alerts', () => {
  const recipient = `alerts-${Date.now()}@example.test`
  let originalAlerts: AlertState | null = null

  test.beforeAll(async ({ browser }) => {
    const context = await browser.newContext({ storageState: 'tests/e2e/.auth/state.json' })
    const page = await context.newPage()
    await gotoSmtp(page, '/notifications')
    originalAlerts = await readAlertState(page)
    await context.close()
  })

  test.afterAll(async ({ browser }) => {
    if (!originalAlerts) return

    const context = await browser.newContext({ storageState: 'tests/e2e/.auth/state.json' })
    const page = await context.newPage()
    await restoreAlertState(page, originalAlerts)
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
    const recipients = formItem(page, 'Recipients').locator('.ant-select-selection-search-input')
    await recipients.click()
    await recipients.fill(recipient)
    await page.keyboard.press('Enter')

    await save(page)

    // Reload — the persisted settings come back (the authoritative check).
    await gotoSmtp(page, '/notifications')
    await expect(formItem(page, 'Failure notifications').getByRole('switch')).toHaveAttribute(
      'aria-checked',
      'true'
    )
    await expect(page.getByText(recipient).first()).toBeVisible()
  })

  test('saves masked Slack and Telegram settings and tests both channels locally', async ({ page }) => {
    const interceptedChannels: string[] = []
    await page.route(`**${NOTIFICATION_TEST_ENDPOINT}`, async route => {
      const request = route.request()
      const payload = JSON.parse(request.postData() || '{}') as { channel?: string }

      if (request.method() !== 'POST' || !['slack', 'telegram'].includes(payload.channel || '')) {
        throw new Error(`Unexpected notification test request: ${request.method()} ${request.url()}`)
      }

      interceptedChannels.push(payload.channel as string)
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({
          status: 'success',
          code: 'SUCCESS',
          data: [],
          message: 'Test notification sent.'
        })
      })
    })

    await gotoSmtp(page, '/notifications')
    await setSwitch(page, 'Failure notifications', true)
    await setSwitch(page, 'Slack notification', true)
    await setSwitch(page, 'Telegram notification', true)

    const slackWebhookUrl = page.getByLabel('Slack webhook URL')
    const telegramBotToken = page.getByLabel('Telegram bot token')
    const telegramChatId = page.getByLabel('Telegram chat ID')
    if (!(await slackWebhookUrl.inputValue())) await slackWebhookUrl.fill(TEST_SLACK_WEBHOOK_URL)
    if (!(await telegramBotToken.inputValue())) await telegramBotToken.fill(TEST_TELEGRAM_BOT_TOKEN)
    if (!(await telegramChatId.inputValue())) await telegramChatId.fill(TEST_TELEGRAM_CHAT_ID)

    await save(page)
    await gotoSmtp(page, '/notifications')

    await expect(page.getByLabel('Slack webhook URL')).toHaveValue(MASK_SENTINEL)
    await expect(page.getByLabel('Telegram bot token')).toHaveValue(MASK_SENTINEL)
    await expect(page.getByLabel('Telegram chat ID')).toHaveValue(
      originalAlerts?.telegramChatId || TEST_TELEGRAM_CHAT_ID
    )

    await testNotificationChannel(page, 'slack')
    await testNotificationChannel(page, 'telegram')
    expect(interceptedChannels).toEqual(['slack', 'telegram'])
  })
})
