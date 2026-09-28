import notify from '@components/Toaster/Toaster'
import { renderWithProviders } from '@config/test-utils'
import useMailSettings from '@pages/Connections/data/useMailSettings'
import useUpdateSettings from '@pages/Connections/data/useUpdateSettings'
import {
  type AlertChannel,
  type FailureAlertSettings,
  MASK_SENTINEL,
  type MailSettings
} from '@pages/Connections/types'
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
  retry_on_classes: ['transient'],
  health_check_enabled: false,
  health_check_interval: 'daily',
  notify_cooldown_minutes: 0,
  notify_events: ['connection_unhealthy'],
  uninstall_purge: true,
  tracking_enabled: false
}

const emailChannel: AlertChannel = {
  id: 'ch_000000000001',
  type: 'email',
  name: 'Ops email',
  enabled: true,
  settings: { recipients: ['ops@example.org'] }
}

const settings: MailSettings = {
  schema_version: 2,
  enabled: true,
  default_connection_id: 'conn_1',
  fallback_connection_ids: [],
  connections: [],
  features: {
    logging: { enabled: true },
    alerts: { enabled: true, reminder_minutes: 60, channels: [emailChannel] }
  }
}

/** Wrap `data` in a successful backend response envelope. */
function apiResponse<T>(data: T) {
  return { status: 'success' as const, code: 'SUCCESS' as const, message: undefined, data }
}

const CONFLICT_MESSAGE = 'Notification settings were changed in another tab. Reload to see the latest.'
const TELEGRAM_BOT_TOKEN = '123456:abcdefghijklmnopqrstuvwxyz'

/** Attach server-owned alerts metadata (revision + a healthy delivery status) to a settings fixture. */
function withAlertsMeta(base: MailSettings, revision: string): MailSettings {
  const alerts = base.features.alerts as FailureAlertSettings

  return {
    ...base,
    features: {
      ...base.features,
      alerts: { ...alerts, revision, delivery: { cron_disabled: false, overdue: false } }
    }
  }
}

let serverSettings: MailSettings
const savePreferencesMutateAsync = vi.fn()
const updateSettingsMutateAsync = vi.fn()

/** Serve `data` from the mocked mail-settings query; the default refetch resolves the latest served settings. */
function mockMailSettings(
  data: MailSettings,
  refetch: Mock = vi.fn(async () => ({ data: serverSettings, isSuccess: true }))
) {
  serverSettings = data
  ;(useMailSettings as Mock).mockReturnValue({ data, isPending: false, refetch })
}

/** Make the alerts save succeed and the server then serve the saved alerts under `revision`. */
function mockAlertsSaveAs(revision: string) {
  updateSettingsMutateAsync.mockImplementation(async (payload: Pick<MailSettings, 'features'>) => {
    mockMailSettings(withAlertsMeta({ ...settings, features: payload.features }, revision))

    return apiResponse(null)
  })
}

/** The alerts revision echoed by the `call`-th (0-based) mail-settings save. */
function echoedRevision(call: number) {
  return updateSettingsMutateAsync.mock.calls[call][0].features.alerts.revision
}

/** Add a recipient to the stored email channel on the Notifications tab, then save. */
async function editRecipientsAndSave() {
  await userEvent.click(screen.getByRole('tab', { name: /notifications/i }))
  await userEvent.type(screen.getByLabelText('Recipients'), 'oncall@example.org{enter}')
  await userEvent.click(screen.getByRole('button', { name: /save changes/i }))
}

/** Add a blank Slack channel through the Notifications tab's Add channel picker. */
async function addSlackChannel() {
  await addChannel('Slack')
}

/** Add a blank channel of the given type through the Notifications tab's Add channel picker. */
async function addChannel(typeLabel: string) {
  await userEvent.click(screen.getByRole('button', { name: 'Add channel' }))
  await userEvent.click(
    within(await screen.findByRole('dialog')).getByRole('button', { name: typeLabel })
  )
}

describe('SettingsPage', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    ;(usePreferences as Mock).mockReturnValue({ data: preferences, isPending: false })
    ;(useSavePreferences as Mock).mockReturnValue({
      mutateAsync: savePreferencesMutateAsync,
      isPending: false
    })
    ;(useExportPreferences as Mock).mockReturnValue({ mutate: vi.fn(), isPending: false })
    ;(useImportPreferences as Mock).mockReturnValue({ mutate: vi.fn(), isPending: false })
    mockMailSettings(settings)
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

  it('marks only saved-off sections with an Off hint in the side menu', () => {
    renderWithProviders(<SettingsPage />)

    const offHint = (tabName: RegExp) =>
      within(screen.getByRole('tab', { name: tabName })).queryByText('Off')
    expect(offHint(/general & logging/i)).not.toBeInTheDocument()
    expect(offHint(/^reliability/i)).toBeInTheDocument()
    expect(offHint(/^health/i)).toBeInTheDocument()
    expect(offHint(/notifications/i)).not.toBeInTheDocument()
    expect(offHint(/privacy & data/i)).not.toBeInTheDocument()
  })

  it("reads a never-visited tab's saved on-state instead of defaulting to off", () => {
    ;(usePreferences as Mock).mockReturnValue({
      data: { ...preferences, health_check_enabled: true },
      isPending: false
    })

    renderWithProviders(<SettingsPage />)

    expect(
      within(screen.getByRole('tab', { name: /^health/i })).queryByText('Off')
    ).not.toBeInTheDocument()
    expect(screen.getByRole('button', { name: /save changes/i })).toBeDisabled()
  })

  it('shows the Off hint for logging when it is saved disabled, instead of a true-biased default', () => {
    ;(usePreferences as Mock).mockReturnValue({
      data: { ...preferences, logging_enabled: false },
      isPending: false
    })

    renderWithProviders(<SettingsPage />)

    expect(
      within(screen.getByRole('tab', { name: /general & logging/i })).getByText('Off')
    ).toBeInTheDocument()
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

  it('shows only the stored failure-alert channel on the Notifications tab', async () => {
    renderWithProviders(<SettingsPage />)

    await userEvent.click(screen.getByRole('tab', { name: /notifications/i }))

    expect(screen.getByLabelText('Name')).toHaveValue('Ops email')
    expect(screen.getByLabelText('Recipients')).toBeInTheDocument()
    expect(screen.queryByLabelText('Alert cooldown (minutes)')).not.toBeInTheDocument()
    expect(screen.queryByLabelText('Webhook URL')).not.toBeInTheDocument()
    expect(screen.queryByLabelText('Slack webhook URL')).not.toBeInTheDocument()
    expect(screen.queryByLabelText('Telegram bot token')).not.toBeInTheDocument()
  })

  it('keeps an unsaved channel card when the mail settings refetch in the background', async () => {
    renderWithProviders(<SettingsPage />)
    await userEvent.click(screen.getByRole('tab', { name: /notifications/i }))
    await userEvent.click(screen.getByRole('button', { name: /add channel/i }))
    await userEvent.click(within(screen.getByRole('dialog')).getByRole('button', { name: /slack/i }))
    expect(screen.getByLabelText('Slack webhook URL')).toBeInTheDocument()
    ;(useMailSettings as Mock).mockReturnValue({ data: { ...settings }, isPending: false })
    await userEvent.click(screen.getByRole('tab', { name: /^health/i }))
    await userEvent.click(screen.getByRole('tab', { name: /notifications/i }))

    expect(screen.getByLabelText('Slack webhook URL')).toBeInTheDocument()
    expect(screen.getByLabelText('Recipients')).toBeInTheDocument()
  })

  it('renders the alert cooldown field on the Health tab, its actual consumer', async () => {
    renderWithProviders(<SettingsPage />)

    await userEvent.click(screen.getByRole('tab', { name: /^health/i }))

    expect(screen.getByLabelText('Alert cooldown (minutes)')).toBeInTheDocument()
    expect(screen.getByLabelText('Alert on these events')).toBeInTheDocument()
  })

  it('persists a newly selected notify_events subscription when saving from the Health tab', async () => {
    ;(usePreferences as Mock).mockReturnValue({
      data: { ...preferences, health_check_enabled: true, notify_events: ['connection_unhealthy'] },
      isPending: false
    })
    renderWithProviders(<SettingsPage />)

    await userEvent.click(screen.getByRole('tab', { name: /^health/i }))
    await userEvent.click(screen.getByLabelText('Alert on these events'))
    await userEvent.click(await screen.findByText('OAuth token expiring'))
    await userEvent.click(screen.getByRole('button', { name: /save changes/i }))

    await waitFor(() => {
      expect(savePreferencesMutateAsync).toHaveBeenCalledWith(
        expect.objectContaining({
          notify_events: ['connection_unhealthy', 'oauth_expiring']
        })
      )
    })
  })

  const anyEventLabel =
    /Connection unhealthy|Connection recovered|OAuth token expiring|OAuth token expired/

  it('durably saves an empty subscription when every alert event is deselected', async () => {
    ;(usePreferences as Mock).mockReturnValue({
      data: {
        ...preferences,
        health_check_enabled: true,
        notify_events: ['connection_unhealthy', 'oauth_expired']
      },
      isPending: false
    })
    renderWithProviders(<SettingsPage />)

    await userEvent.click(screen.getByRole('tab', { name: /^health/i }))

    await userEvent.click(screen.getByLabelText('Alert on these events'))
    await userEvent.keyboard('{Backspace}{Backspace}')

    const events = screen.getByLabelText('Alert on these events').closest('.ant-select') as HTMLElement
    expect(within(events).queryByText(anyEventLabel)).not.toBeInTheDocument()

    await userEvent.click(screen.getByRole('button', { name: /save changes/i }))
    await waitFor(() => {
      expect(savePreferencesMutateAsync).toHaveBeenCalledWith(
        expect.objectContaining({ notify_events: [] })
      )
    })
  })

  it('reloads a stored empty subscription as no selection instead of re-defaulting to all four', async () => {
    ;(usePreferences as Mock).mockReturnValue({
      data: { ...preferences, health_check_enabled: true, notify_events: [] },
      isPending: false
    })
    renderWithProviders(<SettingsPage />)

    await userEvent.click(screen.getByRole('tab', { name: /^health/i }))

    const events = screen.getByLabelText('Alert on these events').closest('.ant-select') as HTMLElement
    expect(within(events).queryByText(anyEventLabel)).not.toBeInTheDocument()
    expect(screen.getByRole('button', { name: /save changes/i })).toBeDisabled()
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
          retry_on_classes: ['transient'],
          notify_events: ['connection_unhealthy']
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
          alerts: {
            enabled: true,
            reminder_minutes: 60,
            channels: [
              { ...emailChannel, settings: { recipients: ['ops@example.org', 'oncall@example.org'] } }
            ]
          }
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

    await userEvent.click(screen.getByRole('tab', { name: /notifications/i }))
    await addSlackChannel()

    await userEvent.click(screen.getByRole('tab', { name: /general & logging/i }))
    await userEvent.click(screen.getByRole('button', { name: /save changes/i }))

    await waitFor(() => {
      expect(notify.error).toHaveBeenCalledWith('Please fix the highlighted fields before saving.')
    })
    expect(screen.getByRole('tab', { name: /notifications/i })).toHaveAttribute('aria-selected', 'true')
    expect(savePreferencesMutateAsync).not.toHaveBeenCalled()
    expect(updateSettingsMutateAsync).not.toHaveBeenCalled()
  })

  it('echoes the loaded alerts revision on save and never posts the read-only delivery status', async () => {
    mockMailSettings(withAlertsMeta(settings, 'rev-1'))
    renderWithProviders(<SettingsPage />)

    await editRecipientsAndSave()

    await waitFor(() => expect(updateSettingsMutateAsync).toHaveBeenCalled())
    const [{ features }] = updateSettingsMutateAsync.mock.calls[0]
    expect(features.alerts.revision).toBe('rev-1')
    expect(features.alerts).not.toHaveProperty('delivery')
  })

  it('shows the server message when a save is rejected as invalid (422)', async () => {
    updateSettingsMutateAsync.mockResolvedValue({
      status: 'error',
      code: 'invalid_alert_channel',
      message: 'The "Ops email" channel needs at least one recipient.',
      data: null
    })
    renderWithProviders(<SettingsPage />)

    await editRecipientsAndSave()

    await waitFor(() =>
      expect(notify.error).toHaveBeenCalledWith('The "Ops email" channel needs at least one recipient.')
    )
  })

  it('offers a Reload that discards local alert edits after a conflicting save (409)', async () => {
    const latest = withAlertsMeta(
      {
        ...settings,
        features: {
          ...settings.features,
          alerts: {
            enabled: true,
            reminder_minutes: 60,
            channels: [{ ...emailChannel, name: 'Renamed elsewhere' }]
          }
        }
      },
      'rev-2'
    )
    const refetch = vi.fn(async () => {
      mockMailSettings(latest)
      return { data: latest, isSuccess: true }
    })
    mockMailSettings(withAlertsMeta(settings, 'rev-1'), refetch)
    updateSettingsMutateAsync.mockResolvedValue({
      status: 'error',
      code: 'alerts_conflict',
      message: CONFLICT_MESSAGE,
      data: null
    })
    renderWithProviders(<SettingsPage />)

    await editRecipientsAndSave()

    const banner = await screen.findByRole('alert')
    expect(banner).toHaveTextContent(CONFLICT_MESSAGE)
    expect(notify.error).not.toHaveBeenCalled()

    await userEvent.click(within(banner).getByRole('button', { name: 'Reload' }))

    await waitFor(() => expect(screen.queryByText(CONFLICT_MESSAGE)).not.toBeInTheDocument())
    expect(refetch).toHaveBeenCalled()
    expect(screen.getByLabelText('Name')).toHaveValue('Renamed elsewhere')
    expect(screen.getByRole('button', { name: /save changes/i })).toBeDisabled()
  })

  it('keeps a newly saved secret masked-and-stored, so an untouched focus/blur never wipes it', async () => {
    updateSettingsMutateAsync.mockImplementation(async (payload: Pick<MailSettings, 'features'>) => {
      const saved = payload.features.alerts as FailureAlertSettings
      const stored = saved.channels.map(channel =>
        channel.type === 'telegram'
          ? { ...channel, settings: { ...channel.settings, bot_token: MASK_SENTINEL } }
          : channel
      )
      mockMailSettings({
        ...settings,
        features: { ...payload.features, alerts: { ...saved, channels: stored } }
      })

      return apiResponse(null)
    })
    renderWithProviders(<SettingsPage />)

    await userEvent.click(screen.getByRole('tab', { name: /notifications/i }))
    await addChannel('Telegram')
    await userEvent.type(screen.getByLabelText('Telegram bot token'), TELEGRAM_BOT_TOKEN)
    await userEvent.type(screen.getByLabelText('Telegram chat ID'), '-1001234567890')
    await userEvent.click(screen.getByRole('button', { name: /save changes/i }))
    await waitFor(() => expect(notify.success).toHaveBeenCalledWith('Settings saved'))
    await waitFor(() => expect(screen.getByLabelText('Telegram bot token')).toHaveValue(MASK_SENTINEL))

    await userEvent.click(screen.getByLabelText('Telegram bot token'))
    await userEvent.click(screen.getByRole('tab', { name: /notifications/i }))

    expect(screen.getByLabelText('Telegram bot token')).toHaveValue(MASK_SENTINEL)
  })

  it('reports only the reload failure after a saved alerts change, then adopts the next background refetch', async () => {
    const failedRefetch = vi.fn(async () => ({ data: undefined, isSuccess: false }))
    mockMailSettings(withAlertsMeta(settings, 'rev-1'), failedRefetch)
    let savedFeatures: MailSettings['features'] = settings.features
    updateSettingsMutateAsync.mockImplementation(async (payload: Pick<MailSettings, 'features'>) => {
      savedFeatures = payload.features

      return apiResponse(null)
    })
    renderWithProviders(<SettingsPage />)

    await editRecipientsAndSave()
    await waitFor(() => expect(notify.error).toHaveBeenCalledWith('Failed to reload settings'))
    expect(notify.success).not.toHaveBeenCalled()

    mockMailSettings(withAlertsMeta({ ...settings, features: savedFeatures }, 'rev-2'))
    await userEvent.click(screen.getByRole('tab', { name: /^health/i }))
    await userEvent.click(screen.getByRole('tab', { name: /notifications/i }))
    await userEvent.type(screen.getByLabelText('Recipients'), 'pager@example.org{enter}')
    await userEvent.click(screen.getByRole('button', { name: /save changes/i }))

    await waitFor(() => expect(updateSettingsMutateAsync).toHaveBeenCalledTimes(2))
    expect(echoedRevision(1)).toBe('rev-2')
  })

  it('adopts the post-save revision even when the preferences half of a combined save fails', async () => {
    mockMailSettings(withAlertsMeta(settings, 'rev-1'))
    mockAlertsSaveAs('rev-2')
    savePreferencesMutateAsync.mockResolvedValue({
      status: 'error',
      code: 'ERROR',
      message: 'Preferences store unavailable',
      data: null
    })
    renderWithProviders(<SettingsPage />)

    await userEvent.clear(screen.getByLabelText('Log retention (days)'))
    await userEvent.type(screen.getByLabelText('Log retention (days)'), '60')
    await editRecipientsAndSave()
    await waitFor(() => expect(notify.error).toHaveBeenCalledWith('Preferences store unavailable'))

    await userEvent.type(screen.getByLabelText('Recipients'), 'pager@example.org{enter}')
    await userEvent.click(screen.getByRole('button', { name: /save changes/i }))

    await waitFor(() => expect(updateSettingsMutateAsync).toHaveBeenCalledTimes(2))
    expect(echoedRevision(0)).toBe('rev-1')
    expect(echoedRevision(1)).toBe('rev-2')
  })

  it('holds Save until the post-save reload lands, so a quick re-save never echoes a stale revision', async () => {
    let finishReload: () => void = () => {}
    const refetch = vi.fn(
      () =>
        new Promise(resolve => {
          finishReload = () => resolve({ data: serverSettings, isSuccess: true })
        })
    )
    mockMailSettings(withAlertsMeta(settings, 'rev-1'), refetch)
    updateSettingsMutateAsync.mockImplementation(async (payload: Pick<MailSettings, 'features'>) => {
      mockMailSettings(withAlertsMeta({ ...settings, features: payload.features }, 'rev-2'), refetch)

      return apiResponse(null)
    })
    renderWithProviders(<SettingsPage />)

    await editRecipientsAndSave()
    await waitFor(() => expect(refetch).toHaveBeenCalled())
    await userEvent.click(screen.getByRole('button', { name: /save changes/i }))
    expect(updateSettingsMutateAsync).toHaveBeenCalledTimes(1)

    finishReload()

    await waitFor(() => expect(notify.success).toHaveBeenCalledWith('Settings saved'))
    expect(screen.getByRole('button', { name: /save changes/i })).toBeDisabled()
    await userEvent.type(screen.getByLabelText('Recipients'), 'pager@example.org{enter}')
    await userEvent.click(screen.getByRole('button', { name: /save changes/i }))
    await waitFor(() => expect(updateSettingsMutateAsync).toHaveBeenCalledTimes(2))
    finishReload()
    expect(echoedRevision(1)).toBe('rev-2')
  })

  it('blocks save for an invalid channel even when that channel is switched off', async () => {
    renderWithProviders(<SettingsPage />)

    await userEvent.click(screen.getByRole('tab', { name: /notifications/i }))
    await addSlackChannel()
    const [, slackEnabled] = screen.getAllByRole('switch', { name: 'Channel enabled' })
    await userEvent.click(slackEnabled)
    await userEvent.click(screen.getByRole('button', { name: /save changes/i }))

    expect(await screen.findByText('Enter a valid Slack incoming webhook URL')).toBeInTheDocument()
    expect(notify.error).toHaveBeenCalledWith('Please fix the highlighted fields before saving.')
    expect(updateSettingsMutateAsync).not.toHaveBeenCalled()
  })
})
