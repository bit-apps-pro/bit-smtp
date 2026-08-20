import { type APIRequestContext, type Page, expect } from '@playwright/test'

export const ADMIN_PAGE = '/wp-admin/admin.php?page=bit-smtp'
const MAILPIT_URL = process.env.E2E_MAILPIT_URL || 'http://localhost:8025'

/** Navigate to a Bit SMTP SPA hash route and wait for the app shell to render. */
export async function gotoSmtp(page: Page, hash = '/'): Promise<void> {
  await page.goto(`${ADMIN_PAGE}#${hash}`)
  await expect(page.getByRole('link', { name: 'Configuration' })).toBeVisible()
}

/** Unique, identifiable name so parallel/leftover runs never collide. */
export function uniqueName(prefix = 'E2E PW'): string {
  return `${prefix} ${Date.now()}`
}

/**
 * Select an antd <Select> option by keyboard (avoids the portal/virtual-list/viewport pitfalls of
 * clicking option elements). On open antd highlights the FIRST option, so we Enter for the first and
 * ArrowDown from there for later ones; the target is confirmed against the rendered selection.
 */
export async function selectAntdOption(
  page: Page,
  formItemLabel: string,
  optionName: string
): Promise<void> {
  const item = page.locator('.ant-form-item').filter({ hasText: formItemLabel })
  const combo = item.getByRole('combobox').first()
  const selection = item.locator('.ant-select-selection-item')
  const dropdown = page.locator('.ant-select-dropdown:not(.ant-select-dropdown-hidden)')
  const wanted = new RegExp(`^${optionName}$`, 'i')

  await combo.scrollIntoViewIfNeeded()
  for (let steps = 0; steps < 12; steps++) {
    await combo.click()
    await expect(dropdown).toBeVisible()
    await combo.press('Home').catch(() => {}) // jump to the first option when supported
    for (let i = 0; i < steps; i++) await combo.press('ArrowDown')
    await combo.press('Enter')
    const text = (
      (await selection
        .first()
        .textContent()
        .catch(() => '')) ?? ''
    ).trim()
    if (wanted.test(text)) return
  }
  throw new Error(`Could not select "${optionName}" in the "${formItemLabel}" select`)
}

/** Open the provider picker, choose Other SMTP, and fill an SMTP->mailpit connection form. */
export async function fillOtherSmtpMailpit(page: Page, name: string, fromEmail: string): Promise<void> {
  await page.getByRole('button', { name: 'Add connection' }).click()
  const dialog = page.getByRole('dialog', { name: 'Choose a provider' })
  await expect(dialog).toBeVisible()
  await dialog.getByRole('button', { name: 'Other SMTP', exact: true }).click()
  await expect(page.getByRole('button', { name: 'Save' })).toBeVisible()

  await page.getByLabel('Name', { exact: true }).fill(name)
  await page.getByLabel('From Email', { exact: true }).fill(fromEmail)
  const host = page.getByLabel('SMTP Host', { exact: true })
  await host.click()
  await host.fill('127.0.0.1')
  await expect(host).toHaveValue('127.0.0.1')
  await page.getByLabel('SMTP Port', { exact: true }).fill('1025')
  await selectAntdOption(page, 'Encryption', 'None')
}

/** Mailpit REST helpers (the plugin's SMTP send lands here). */
export const mailpit = {
  async deleteAll(request: APIRequestContext): Promise<void> {
    await request.delete(`${MAILPIT_URL}/api/v1/messages`)
  },
  async search(
    request: APIRequestContext,
    query: string
  ): Promise<{ messages_count: number; messages: unknown[] }> {
    const res = await request.get(`${MAILPIT_URL}/api/v1/search?query=${encodeURIComponent(query)}`)
    return res.json()
  }
}
