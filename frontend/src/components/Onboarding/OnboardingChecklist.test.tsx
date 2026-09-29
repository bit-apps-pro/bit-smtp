import request from '@common/helpers/request'
import { renderWithProviders } from '@config/test-utils'
import { type MailSettings } from '@pages/Connections/types'
import { screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, describe, expect, it, vi } from 'vitest'
import OnboardingChecklist, { isSetupComplete, onboardingSteps } from './OnboardingChecklist'
import { readOnboardingFlags, updateOnboardingFlags } from './onboardingStorage'

vi.mock('@common/helpers/request', () => ({ default: vi.fn() }))

const FLAGS = { checklistDismissed: false, testEmailSent: false, reviewPromptDismissed: false }

/** Minimal mail settings with the given number of connections and alert switch. */
function settingsWith(connectionCount: number, alertsOn = false): MailSettings {
  return {
    schema_version: 1,
    enabled: true,
    default_connection_id: '',
    fallback_connection_ids: [],
    connections: Array.from({ length: connectionCount }, (_, i) => ({
      id: `c${i}`,
      provider: 'smtp',
      kind: 'smtp',
      name: `Conn ${i}`,
      enabled: true,
      fromEmail: '',
      fromName: '',
      replyToEmail: '',
      settings: {},
      credentials: {}
    })),
    features: { alerts: { enabled: alertsOn, reminder_minutes: 60, channels: [] } }
  }
}

/** Make the settings endpoint answer with the given settings. */
function serveSettings(settings: MailSettings) {
  vi.mocked(request).mockResolvedValue({
    status: 'success',
    code: 'SUCCESS',
    message: undefined,
    data: { settings } as never
  })
}

describe('onboardingSteps', () => {
  it('marks steps done from connections, the test flag and the alerts switch', () => {
    const steps = onboardingSteps(settingsWith(1, true), { ...FLAGS, testEmailSent: true })
    expect(steps.map(step => step.done)).toEqual([true, true, true])
  })

  it('marks the test step done when the server has recorded a successful test send', () => {
    const test = onboardingSteps(settingsWith(1), FLAGS, true).find(step => step.key === 'test')
    expect(test?.done).toBe(true)
  })

  it('treats alerts as optional when deciding setup is complete', () => {
    const steps = onboardingSteps(settingsWith(1), { ...FLAGS, testEmailSent: true })
    expect(isSetupComplete(steps)).toBe(true)
  })

  it('links alerts to the notifications settings tab', () => {
    const alerts = onboardingSteps(settingsWith(0), FLAGS).find(step => step.key === 'alerts')
    expect(alerts?.cta.to).toBe('/settings?tab=notifications')
  })
})

describe('OnboardingChecklist', () => {
  afterEach(() => {
    localStorage.clear()
    vi.mocked(request).mockReset()
  })

  it('shows the checklist on a fresh install', async () => {
    serveSettings(settingsWith(0))
    renderWithProviders(<OnboardingChecklist />)

    expect(await screen.findByRole('region', { name: 'Get started with Bit SMTP' })).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Add connection' })).toBeInTheDocument()
  })

  it('hides itself once a connection exists and a test email was sent', async () => {
    updateOnboardingFlags({ testEmailSent: true })
    serveSettings(settingsWith(1))
    renderWithProviders(<OnboardingChecklist />)

    await waitFor(() => expect(request).toHaveBeenCalled())
    expect(screen.queryByRole('region', { name: 'Get started with Bit SMTP' })).not.toBeInTheDocument()
  })

  it('stays dismissed after the admin closes it', async () => {
    serveSettings(settingsWith(0))
    renderWithProviders(<OnboardingChecklist />)

    await userEvent.click(await screen.findByRole('button', { name: 'Dismiss setup checklist' }))

    expect(screen.queryByRole('region', { name: 'Get started with Bit SMTP' })).not.toBeInTheDocument()
    expect(readOnboardingFlags().checklistDismissed).toBe(true)
  })
})
