import { type Locator, type Page, type Request, expect, test } from '@playwright/test'
import { gotoSmtp, uniqueName } from './helpers'

const MASK_SENTINEL = '********'
const TEST_SLACK_WEBHOOK_URL = 'https://hooks.slack.com/services/T00000000/B00000000/e2e-secret'
const NOTIFICATION_TEST_ENDPOINT = '/wp-json/bit-smtp/v1/mail/notifications/test'
const CHANNEL_ID_PATTERN = /^ch_[0-9a-f]{12}$/
const CHANNEL_NAME_PREFIX = 'E2E Alert'
const MASTER_SWITCH_LABEL = 'Enable failure notifications'

/** Open the Settings page on its Notifications tab. */
async function gotoNotifications(page: Page): Promise<void> {
  await gotoSmtp(page, '/settings')
  await page.getByRole('tab', { name: /notifications/i }).click()
  await expect(page.getByRole('switch', { name: MASTER_SWITCH_LABEL })).toBeVisible()
}

/** Toggle the failure-notifications master switch to the desired state. */
async function setMasterSwitch(page: Page, on: boolean): Promise<void> {
  const master = page.getByRole('switch', { name: MASTER_SWITCH_LABEL })
  if ((await master.getAttribute('aria-checked')) !== String(on)) await master.click()
}

/** Every channel card on the Notifications tab. */
function channelCards(page: Page): Locator {
  return page.locator('.ant-card').filter({ has: page.getByLabel('Name', { exact: true }) })
}

/** The channel card whose header shows `name`. */
function channelCard(page: Page, name: string): Locator {
  return channelCards(page).filter({ hasText: name })
}

/** Add a channel of `typeLabel` via the picker, rename it to `name`, and return its card. */
async function addChannel(page: Page, typeLabel: string, name: string): Promise<Locator> {
  await page.getByRole('button', { name: 'Add channel' }).first().click()
  await page.getByRole('dialog').getByRole('button', { name: typeLabel, exact: true }).click()
  await channelCards(page).last().getByLabel('Name', { exact: true }).fill(name)

  return channelCard(page, name)
}

/** Click Save changes and wait for the mail-settings save request to succeed. */
async function save(page: Page): Promise<void> {
  const done = page.waitForResponse(
    response =>
      response.request().method() === 'POST' &&
      new URL(response.url()).pathname.endsWith('/mail/settings/save') &&
      response.ok(),
    { timeout: 15_000 }
  )
  await page.getByRole('button', { name: 'Save changes' }).click()
  await done
}

/** Remove every channel card this suite created, identified by its name prefix. */
async function removeTestChannels(page: Page): Promise<void> {
  const leftovers = channelCards(page).filter({ hasText: CHANNEL_NAME_PREFIX })

  while ((await leftovers.count()) > 0) {
    await leftovers
      .first()
      .getByRole('button', { name: /remove/i })
      .click()
    await page.locator('.ant-popconfirm').getByRole('button', { name: 'Remove' }).click()
  }
}

/** Assert a Send test request carries one channel instance's id, type, and live settings. */
function assertNotificationTestRequest(
  request: Request,
  type: string,
  settings: Record<string, unknown>
): void {
  const payload = JSON.parse(request.postData() || '{}') as { channel?: Record<string, unknown> }

  expect(request.method()).toBe('POST')
  expect(new URL(request.url()).pathname).toBe(NOTIFICATION_TEST_ENDPOINT)
  expect(payload.channel?.id).toMatch(CHANNEL_ID_PATTERN)
  expect(payload.channel).toMatchObject({ type, settings })
}

// Flow 3: add failure-notification channel instances, test one unsaved, save, and confirm persistence.
test.describe('Failure-notification alerts', () => {
  let originalMasterOn = false

  test.beforeAll(async ({ browser }) => {
    const context = await browser.newContext({ storageState: 'tests/e2e/.auth/state.json' })
    const page = await context.newPage()
    await gotoNotifications(page)
    originalMasterOn =
      (await page.getByRole('switch', { name: MASTER_SWITCH_LABEL }).getAttribute('aria-checked')) ===
      'true'
    await context.close()
  })

  test.afterAll(async ({ browser }) => {
    const context = await browser.newContext({ storageState: 'tests/e2e/.auth/state.json' })
    const page = await context.newPage()
    await gotoNotifications(page)
    await removeTestChannels(page)
    await setMasterSwitch(page, originalMasterOn)
    if (await page.getByRole('button', { name: 'Save changes' }).isEnabled()) await save(page)
    await context.close()
  })

  test('adds an Email channel with a recipient and persists it', async ({ page }) => {
    const name = uniqueName(`${CHANNEL_NAME_PREFIX} Email`)
    const recipient = `alerts-${Date.now()}@example.test`

    await gotoNotifications(page)
    await setMasterSwitch(page, true)
    const card = await addChannel(page, 'Email', name)

    const recipients = card.locator('.ant-select-selection-search-input')
    await recipients.click()
    await recipients.fill(recipient)
    await page.keyboard.press('Enter')

    await save(page)

    await gotoNotifications(page)
    await expect(page.getByRole('switch', { name: MASTER_SWITCH_LABEL })).toHaveAttribute(
      'aria-checked',
      'true'
    )
    await expect(channelCard(page, name).getByText(recipient)).toBeVisible()
  })

  test('tests an unsaved Slack channel with its live values, then saves it masked', async ({ page }) => {
    const name = uniqueName(`${CHANNEL_NAME_PREFIX} Slack`)
    await page.route(`**${NOTIFICATION_TEST_ENDPOINT}`, async route => {
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

    await gotoNotifications(page)
    const card = await addChannel(page, 'Slack', name)
    await card.getByLabel('Slack webhook URL').fill(TEST_SLACK_WEBHOOK_URL)

    const testRequest = page.waitForRequest(
      candidate => new URL(candidate.url()).pathname === NOTIFICATION_TEST_ENDPOINT
    )
    await card.getByRole('button', { name: /send test/i }).click()
    assertNotificationTestRequest(await testRequest, 'slack', { webhook_url: TEST_SLACK_WEBHOOK_URL })
    await expect(page.getByText('Test notification sent')).toBeVisible()

    await save(page)
    await gotoNotifications(page)

    await expect(channelCard(page, name).getByLabel('Slack webhook URL')).toHaveValue(MASK_SENTINEL)
  })
})
