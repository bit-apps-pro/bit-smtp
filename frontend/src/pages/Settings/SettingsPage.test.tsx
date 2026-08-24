import notify from '@components/Toaster/Toaster'
import { renderWithProviders } from '@config/test-utils'
import useMailSettings from '@pages/Connections/data/useMailSettings'
import useUpdateSettings from '@pages/Connections/data/useUpdateSettings'
import { type MailSettings } from '@pages/Connections/types'
import usePreferences, {
  useExportPreferences,
  useImportPreferences,
  useSavePreferences
} from '@pages/Settings/data/usePreferences'
import useTestNotification from '@pages/Settings/data/useTestNotification'
import { type Preferences } from '@pages/Settings/types'
import { screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { type Mock, beforeEach, describe, expect, it, vi } from 'vitest'
import SettingsPage from './SettingsPage'

vi.mock('@components/Toaster/Toaster', () => ({
  default: { success: vi.fn(), error: vi.fn() }
}))
vi.mock('@pages/Settings/data/usePreferences', () => ({
  default: vi.fn(),
  useSavePreferences: vi.fn(),
  useExportPreferences: vi.fn(),
  useImportPreferences: vi.fn()
}))
vi.mock('@pages/Connections/data/useMailSettings', () => ({ default: vi.fn() }))
vi.mock('@pages/Connections/data/useUpdateSettings', () => ({ default: vi.fn() }))
vi.mock('@pages/Settings/data/useTestNotification', () => ({ default: vi.fn() }))

const preferences: Preferences = {
  logging_enabled: true,
  log_retention_days: 30,
  log_store_body: 'full',
  send_timeout_seconds: 30,
  retry_enabled: false,
  retry_max_attempts: 3,
  retry_backoff: 'exponential',
  retry_on_classes: ['TransportException'],
  health_check_enabled: false,
  health_check_interval: 'daily',
  notify_cooldown_minutes: 0,
  notify_events: ['connection.failed'],
  uninstall_purge: true,
  tracking_enabled: false
}

const settings: MailSettings = {
  schema_version: 2,
  enabled: true,
  default_connection_id: 'conn_1',
  fallback_connection_ids: [],
  connections: [],
  features: {
    logging: { enabled: true },
    alerts: {
      enabled: true,
      email: { enabled: true, recipients: ['ops@example.org'] },
      webhook: { enabled: false, url: '', signing_secret: '' },
      slack: { enabled: false, webhook_url: '' },
      telegram: { enabled: false, bot_token: '', chat_id: '' }
    }
  }
}

function apiResponse<T>(data: T) {
  return { status: 'success' as const, code: 'SUCCESS' as const, message: undefined, data }
}

describe('SettingsPage', () => {
  const savePreferencesMutateAsync = vi.fn()
  const updateSettingsMutateAsync = vi.fn()

  beforeEach(() => {
    vi.clearAllMocks()
    ;(usePreferences as Mock).mockReturnValue({ data: preferences, isPending: false })
    ;(useSavePreferences as Mock).mockReturnValue({
      mutateAsync: savePreferencesMutateAsync,
      isPending: false
    })
    ;(useExportPreferences as Mock).mockReturnValue({ mutate: vi.fn(), isPending: false })
    ;(useImportPreferences as Mock).mockReturnValue({ mutate: vi.fn(), isPending: false })
    ;(useMailSettings as Mock).mockReturnValue({ data: settings, isPending: false })
    ;(useUpdateSettings as Mock).mockReturnValue({
      mutateAsync: updateSettingsMutateAsync,
      isPending: false
    })
    ;(useTestNotification as Mock).mockReturnValue({ mutate: vi.fn(), isPending: false })
    savePreferencesMutateAsync.mockResolvedValue(apiResponse({ preferences }))
    updateSettingsMutateAsync.mockResolvedValue(apiResponse({ settings }))
  })

  it('shows a loading spinner while preferences or mail settings are pending', () => {
    ;(usePreferences as Mock).mockReturnValue({ data: undefined, isPending: true })

    const { container } = renderWithProviders(<SettingsPage />)

    expect(container.querySelector('.ant-spin')).toBeInTheDocument()
  })

  it('renders all five settings tabs', () => {
    renderWithProviders(<SettingsPage />)

    expect(screen.getByRole('tab', { name: /general & logging/i })).toBeInTheDocument()
    expect(screen.getByRole('tab', { name: /^reliability/i })).toBeInTheDocument()
    expect(screen.getByRole('tab', { name: /^health/i })).toBeInTheDocument()
    expect(screen.getByRole('tab', { name: /notifications/i })).toBeInTheDocument()
    expect(screen.getByRole('tab', { name: /privacy & data/i })).toBeInTheDocument()
  })

  it('summarizes preferences in the status strip from loaded state', () => {
    renderWithProviders(<SettingsPage />)

    const strip = screen.getByRole('group', { name: /settings status/i })
    expect(within(strip).getByText('Logging')).toBeInTheDocument()
    expect(within(strip).getByText('Retention')).toBeInTheDocument()
    expect(within(strip).getByText('Timeout')).toBeInTheDocument()
    expect(within(strip).getByText('Notifications')).toBeInTheDocument()
    expect(within(strip).getByText('Health')).toBeInTheDocument()
    expect(within(strip).getByText('30d')).toBeInTheDocument()
    expect(within(strip).getByText('30s')).toBeInTheDocument()
    // Logging + notifications are on in the fixture, health is off — and notifications reads on from
    // saved settings without visiting its (lazily-mounted) tab, proving the loaded-value fallback.
    expect(within(strip).getAllByText('On')).toHaveLength(2)
    expect(within(strip).getByText('Off')).toBeInTheDocument()
  })

  it('reflects a live edit in the status strip', async () => {
    renderWithProviders(<SettingsPage />)

    await userEvent.clear(screen.getByLabelText('Log retention (days)'))
    await userEvent.type(screen.getByLabelText('Log retention (days)'), '60')

    const strip = screen.getByRole('group', { name: /settings status/i })
    expect(within(strip).getByText('60d')).toBeInTheDocument()
  })

  it("reads a never-visited tab's saved on-state instead of defaulting to off", () => {
    // Regression for the dirty-tracking fix: the Health tab's own field-level fallback (`?? false`)
    // only governs the tab dot's pre-visit appearance. The status strip must instead fall back to
    // the loaded value, or a true saved flag would misreport as Off here — the Health tab is never
    // opened in this test, so health_check_enabled only reaches the strip via that loaded-value path.
    ;(usePreferences as Mock).mockReturnValue({
      data: { ...preferences, health_check_enabled: true },
      isPending: false
    })

    renderWithProviders(<SettingsPage />)

    const strip = screen.getByRole('group', { name: /settings status/i })
    expect(within(strip).getAllByText('On')).toHaveLength(3) // logging, notifications, health
    expect(within(strip).queryByText('Off')).not.toBeInTheDocument()
    // Merely loading a true saved flag must not itself mark the form dirty.
    expect(screen.getByRole('button', { name: /save changes/i })).toBeDisabled()
  })

  it('marks the Health and Notifications tab dots active from saved state, not just the strip, without visiting either tab', () => {
    // Regression: the tab dots used to collapse the raw (pre-mount) watch with `?? false` instead of
    // falling back to the saved value like the strip does, so a saved-on feature whose tab was never
    // opened rendered a gray dot while the strip correctly showed On — a visible contradiction.
    ;(usePreferences as Mock).mockReturnValue({
      data: { ...preferences, health_check_enabled: true },
      isPending: false
    })

    renderWithProviders(<SettingsPage />)

    const dotColor = (tabName: RegExp) => {
      const dots = screen.getByRole('tab', { name: tabName }).querySelectorAll('[aria-hidden="true"]')
      return (dots[dots.length - 1] as HTMLElement).style.backgroundColor
    }

    // Logging (saved on) and Reliability (retry_enabled: false, saved off) are known-good references,
    // since their tab dots already collapsed the raw watch correctly before this fix.
    const onColor = dotColor(/general & logging/i)
    const offColor = dotColor(/^reliability/i)
    expect(onColor).not.toBe(offColor)

    // Health (forced saved-on above) and Notifications (saved on via the settings fixture) must read
    // as active even though neither tab was ever mounted.
    expect(dotColor(/^health/i)).toBe(onColor)
    expect(dotColor(/notifications/i)).toBe(onColor)
  })

  it('shows the Logging strip chip as Off when logging is saved disabled, instead of the true-biased default', () => {
    // Regression: the Logging chip's live value used to fall back to `?? true`, so a saved-disabled
    // logging state flashed On on first paint, before the field was ever touched.
    ;(usePreferences as Mock).mockReturnValue({
      data: { ...preferences, logging_enabled: false },
      isPending: false
    })

    renderWithProviders(<SettingsPage />)

    const strip = screen.getByRole('group', { name: /settings status/i })
    const loggingChip = within(strip).getByText('Logging').parentElement as HTMLElement
    expect(within(loggingChip).getByText('Off')).toBeInTheDocument()
    expect(within(loggingChip).queryByText('On')).not.toBeInTheDocument()
  })

  it('enables the Save changes button once a field is edited', async () => {
    renderWithProviders(<SettingsPage />)

    const saveButton = screen.getByRole('button', { name: /save changes/i })
    expect(saveButton).toBeDisabled()

    await userEvent.clear(screen.getByLabelText('Log retention (days)'))
    await userEvent.type(screen.getByLabelText('Log retention (days)'), '60')

    expect(saveButton).toBeEnabled()
    expect(screen.getByText('You have unsaved changes')).toBeInTheDocument()
  })

  it('shows only the added failure-alert channel on the Notifications tab', async () => {
    // Fixture has only the Email channel enabled; Webhook/Slack/Telegram are off, so their cards
    // aren't added yet and their fields shouldn't render (the redesigned "added channels" model).
    renderWithProviders(<SettingsPage />)

    await userEvent.click(screen.getByRole('tab', { name: /notifications/i }))

    expect(screen.getByLabelText('Recipients')).toBeInTheDocument()
    expect(screen.getByLabelText('Notification cooldown (minutes)')).toBeInTheDocument()
    expect(screen.queryByLabelText('Webhook URL')).not.toBeInTheDocument()
    expect(screen.queryByLabelText('Slack webhook URL')).not.toBeInTheDocument()
    expect(screen.queryByLabelText('Telegram bot token')).not.toBeInTheDocument()
  })

  it('saves only the preferences store when a General field changes', async () => {
    renderWithProviders(<SettingsPage />)

    await userEvent.clear(screen.getByLabelText('Log retention (days)'))
    await userEvent.type(screen.getByLabelText('Log retention (days)'), '60')
    await userEvent.click(screen.getByRole('button', { name: /save changes/i }))

    await waitFor(() => {
      expect(savePreferencesMutateAsync).toHaveBeenCalledWith(
        expect.objectContaining({
          log_retention_days: 60,
          retry_on_classes: ['TransportException'],
          notify_events: ['connection.failed']
        })
      )
    })
    expect(updateSettingsMutateAsync).not.toHaveBeenCalled()
  })

  it('saves only the mail-settings store when a Notifications channel field changes', async () => {
    renderWithProviders(<SettingsPage />)

    await userEvent.click(screen.getByRole('tab', { name: /notifications/i }))
    await userEvent.type(screen.getByLabelText('Recipients'), 'oncall@example.org{enter}')
    await userEvent.click(screen.getByRole('button', { name: /save changes/i }))

    await waitFor(() => {
      expect(updateSettingsMutateAsync).toHaveBeenCalledWith({
        features: {
          logging: { enabled: true },
          alerts: expect.objectContaining({
            email: expect.objectContaining({
              recipients: ['ops@example.org', 'oncall@example.org']
            })
          })
        }
      })
    })
    expect(savePreferencesMutateAsync).not.toHaveBeenCalled()
  })

  it('blocks save and shows inline validation when log_retention_days is out of range', async () => {
    renderWithProviders(<SettingsPage />)

    await userEvent.clear(screen.getByLabelText('Log retention (days)'))
    await userEvent.type(screen.getByLabelText('Log retention (days)'), '500')
    await userEvent.click(screen.getByRole('button', { name: /save changes/i }))

    expect(await screen.findByText('Enter a number of days between 1 and 200')).toBeInTheDocument()
    expect(savePreferencesMutateAsync).not.toHaveBeenCalled()
  })

  it('disables the Save changes button until a field is edited', () => {
    renderWithProviders(<SettingsPage />)

    expect(screen.getByRole('button', { name: /save changes/i })).toBeDisabled()
  })

  it('shows an error toast and jumps to the Notifications tab when saving from another tab with an invalid alerts channel', async () => {
    renderWithProviders(<SettingsPage />)

    // Add an invalid (blank URL) Slack channel on the Notifications tab, matching the reported bug:
    // an invalid field left on a hidden tab, then Save clicked from a different, valid-looking tab.
    await userEvent.click(screen.getByRole('tab', { name: /notifications/i }))
    await userEvent.click(screen.getByRole('button', { name: 'Add channel' }))
    await userEvent.click(screen.getByRole('button', { name: 'Slack' }))

    await userEvent.click(screen.getByRole('tab', { name: /general & logging/i }))
    await userEvent.click(screen.getByRole('button', { name: /save changes/i }))

    await waitFor(() => {
      expect(notify.error).toHaveBeenCalledWith('Please fix the highlighted fields before saving.')
    })
    expect(screen.getByRole('tab', { name: /notifications/i })).toHaveAttribute('aria-selected', 'true')
    expect(savePreferencesMutateAsync).not.toHaveBeenCalled()
    expect(updateSettingsMutateAsync).not.toHaveBeenCalled()
  })
})
