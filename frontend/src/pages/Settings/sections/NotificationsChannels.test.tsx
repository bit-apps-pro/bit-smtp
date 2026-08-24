import notify from '@components/Toaster/Toaster'
import { type MailSettings } from '@pages/Connections/types'
import useTestNotification from '@pages/Settings/data/useTestNotification'
import { type PreferencesFormValues } from '@pages/Settings/types'
import { render, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { Form } from 'antd'
import { type Mock, beforeEach, describe, expect, it, vi } from 'vitest'
import NotificationsChannels, { type NotificationFormValues } from './NotificationsChannels'

vi.mock('@components/Toaster/Toaster', () => ({
  default: { success: vi.fn(), error: vi.fn() }
}))
vi.mock('@pages/Settings/data/useTestNotification', () => ({ default: vi.fn() }))

const prefsInitialValues: PreferencesFormValues = {
  logging_enabled: true,
  log_retention_days: 30,
  log_store_body: 'full',
  send_timeout_seconds: 30,
  retry_enabled: false,
  retry_max_attempts: 3,
  retry_backoff: 'exponential',
  health_check_enabled: false,
  health_check_interval: 'daily',
  notify_cooldown_minutes: 15,
  uninstall_purge: true,
  tracking_enabled: false
}

// All four channels added, mirroring a fully-configured account. Most existing per-channel
// behavior tests below reuse this so every card is present without extra add-flow steps.
const allChannelsSettings: MailSettings = {
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
      webhook: {
        enabled: true,
        url: '********',
        signing_secret: '********'
      },
      slack: {
        enabled: true,
        webhook_url: '********'
      },
      telegram: {
        enabled: true,
        bot_token: '********',
        chat_id: '-1001234567890'
      }
    }
  }
}

// Only Slack added, with a real (unmasked) saved URL so remove/re-add clearing is observable.
const slackOnlySettings: MailSettings = {
  ...allChannelsSettings,
  features: {
    ...allChannelsSettings.features,
    alerts: {
      enabled: true,
      email: { enabled: false, recipients: [] },
      webhook: { enabled: false, url: '', signing_secret: '' },
      slack: { enabled: true, webhook_url: 'https://hooks.slack.com/services/T000/B000/secret' },
      telegram: { enabled: false, bot_token: '', chat_id: '' }
    }
  }
}

const noChannelsSettings: MailSettings = {
  ...allChannelsSettings,
  features: {
    ...allChannelsSettings.features,
    alerts: {
      enabled: true,
      email: { enabled: false, recipients: [] },
      webhook: { enabled: false, url: '', signing_secret: '' },
      slack: { enabled: false, webhook_url: '' },
      telegram: { enabled: false, bot_token: '', chat_id: '' }
    }
  }
}

/** Test harness: mounts NotificationsChannels with real Form instances, mirroring SettingsPage's wiring. */
function Harness({ settingsOverride }: { settingsOverride?: MailSettings }) {
  const [prefsForm] = Form.useForm<PreferencesFormValues>()
  const [alertsForm] = Form.useForm<NotificationFormValues>()

  return (
    <NotificationsChannels
      alertsForm={alertsForm}
      prefsForm={prefsForm}
      prefsInitialValues={prefsInitialValues}
      settings={settingsOverride ?? allChannelsSettings}
    />
  )
}

describe('NotificationsChannels', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    ;(useTestNotification as Mock).mockReturnValue({ mutate: vi.fn(), isPending: false })
  })

  it('renders the carried-through preferences cooldown field alongside the alert channels', () => {
    render(<Harness />)

    expect((screen.getByLabelText('Notification cooldown (minutes)') as HTMLInputElement).value).toBe(
      '15'
    )
  })

  it('renders only the added channel when just one is enabled', () => {
    render(<Harness settingsOverride={slackOnlySettings} />)

    expect(screen.getByLabelText('Slack webhook URL')).toBeInTheDocument()
    expect(screen.queryByLabelText('Recipients')).not.toBeInTheDocument()
    expect(screen.queryByLabelText('Webhook URL')).not.toBeInTheDocument()
    expect(screen.queryByLabelText('Telegram bot token')).not.toBeInTheDocument()
  })

  it('shows the empty state and no cards when no channel has been added', () => {
    render(<Harness settingsOverride={noChannelsSettings} />)

    expect(screen.getByText('No notification channels yet')).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Add channel' })).toBeInTheDocument()
    expect(screen.queryByLabelText('Recipients')).not.toBeInTheDocument()
  })

  it('shows the empty state when legacy alerts omit the Slack/Telegram sections and nothing else is enabled', () => {
    const legacySettings: MailSettings = {
      ...allChannelsSettings,
      features: {
        ...allChannelsSettings.features,
        alerts: {
          enabled: true,
          email: { enabled: false, recipients: [] },
          webhook: { enabled: false, url: '', signing_secret: '' }
        } as unknown as MailSettings['features']['alerts']
      }
    }

    render(<Harness settingsOverride={legacySettings} />)

    expect(screen.getByText('No notification channels yet')).toBeInTheDocument()
    expect(screen.queryByLabelText('Slack webhook URL')).not.toBeInTheDocument()
    expect(screen.queryByLabelText('Telegram bot token')).not.toBeInTheDocument()
  })

  it('opens the Add channel picker listing only the un-added channel types', async () => {
    render(<Harness settingsOverride={slackOnlySettings} />)

    await userEvent.click(screen.getByRole('button', { name: 'Add channel' }))

    expect(screen.getByText('Add a channel')).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Email' })).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Webhook' })).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Telegram' })).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Slack' })).not.toBeInTheDocument()
  })

  it('reveals a channel card with blank fields after picking it from the Add channel modal', async () => {
    render(<Harness settingsOverride={noChannelsSettings} />)

    await userEvent.click(screen.getByRole('button', { name: 'Add channel' }))
    await userEvent.click(screen.getByRole('button', { name: 'Email' }))

    expect(screen.getByLabelText('Recipients')).toBeInTheDocument()
  })

  it('hides a channel card after Remove is confirmed and clears its saved value on re-add', async () => {
    render(<Harness settingsOverride={slackOnlySettings} />)

    await userEvent.click(screen.getByRole('button', { name: /remove/i }))
    await userEvent.click(await screen.findByRole('button', { name: 'OK' }))

    expect(screen.queryByLabelText('Slack webhook URL')).not.toBeInTheDocument()

    await userEvent.click(screen.getByRole('button', { name: 'Add channel' }))
    await userEvent.click(screen.getByRole('button', { name: 'Slack' }))

    expect(screen.getByLabelText('Slack webhook URL')).toHaveValue('')
  })

  it('generates a whsec signing secret', async () => {
    render(<Harness />)

    await userEvent.click(screen.getByRole('button', { name: /generate signing secret/i }))

    expect((screen.getByLabelText('Signing secret') as HTMLInputElement).value).toMatch(
      /^whsec_[a-f0-9]{64}$/
    )
  })

  it('shows a validation error when an enabled email channel loses its recipients', async () => {
    const user = userEvent.setup()
    render(<Harness />)

    // The fixture starts with one recipient tag; Backspace on the empty search input removes it,
    // firing onChange([]) so the validator runs (tags Select has no "clear" affordance to target).
    await user.click(screen.getByLabelText('Recipients'))
    await user.keyboard('{Backspace}')

    expect(await screen.findByText('Add at least one recipient')).toBeInTheDocument()
  })

  it('hydrates saved Slack and Telegram secrets as masked password fields', () => {
    render(<Harness />)

    expect((screen.getByLabelText('Slack webhook URL') as HTMLInputElement).value).toBe('********')
    expect((screen.getByLabelText('Telegram bot token') as HTMLInputElement).value).toBe('********')
    expect((screen.getByLabelText('Telegram chat ID') as HTMLInputElement).value).toBe('-1001234567890')
  })

  it('keeps channel fields and Add channel disabled until failure notifications are enabled, but leaves Remove usable', async () => {
    const user = userEvent.setup()
    // Only Slack is added (unlike the default all-channels fixture) so the "Add channel" button is
    // actually rendered (hidden once every channel type is already added).
    render(<Harness settingsOverride={slackOnlySettings} />)

    const slackUrl = screen.getByLabelText('Slack webhook URL')
    const addChannelButton = screen.getByRole('button', { name: 'Add channel' })
    const removeButtons = screen.getAllByRole('button', { name: /remove/i })
    expect(slackUrl).not.toBeDisabled()
    removeButtons.forEach(button => expect(button).not.toBeDisabled())

    await user.click(screen.getByRole('switch', { name: 'Enable failure notifications' }))

    expect(slackUrl).toBeDisabled()
    expect(addChannelButton).toBeDisabled()
    // Remove is a cleanup/destructive action, not a configuration edit — it must stay available even
    // with the master switch off, so a stale channel (and its stored secret) can still be purged.
    removeButtons.forEach(button => expect(button).not.toBeDisabled())
  })

  it('allows testing valid saved channels while global failure alerts are off', () => {
    const { alerts } = allChannelsSettings.features
    if (!alerts || Array.isArray(alerts)) {
      throw new Error('Expected alert settings fixture')
    }

    render(
      <Harness
        settingsOverride={{
          ...allChannelsSettings,
          features: { ...allChannelsSettings.features, alerts: { ...alerts, enabled: false } }
        }}
      />
    )

    expect(screen.getByRole('button', { name: 'Test Slack notification' })).toBeEnabled()
    expect(screen.getByRole('button', { name: 'Test Telegram notification' })).toBeEnabled()
  })

  it('disables only the channel test whose saved target has unsaved changes', async () => {
    const user = userEvent.setup()
    render(<Harness />)

    const slackTest = screen.getByRole('button', { name: 'Test Slack notification' })
    const telegramTest = screen.getByRole('button', { name: 'Test Telegram notification' })
    expect(slackTest).toBeEnabled()
    expect(telegramTest).toBeEnabled()

    await user.clear(screen.getByLabelText('Slack webhook URL'))
    await user.type(
      screen.getByLabelText('Slack webhook URL'),
      'https://hooks.slack.com/services/T00000000/B00000000/new-target'
    )

    expect(slackTest).toBeDisabled()
    expect(telegramTest).toBeEnabled()

    await user.clear(screen.getByLabelText('Telegram chat ID'))
    await user.type(screen.getByLabelText('Telegram chat ID'), '-1007654321000')

    expect(telegramTest).toBeDisabled()
  })

  it('shows inline validation errors for invalid Slack and Telegram configuration', async () => {
    const user = userEvent.setup()
    render(<Harness />)

    await user.clear(screen.getByLabelText('Slack webhook URL'))
    await user.type(screen.getByLabelText('Slack webhook URL'), 'https://example.com/not-slack')
    await user.tab()
    await user.clear(screen.getByLabelText('Telegram bot token'))
    await user.type(screen.getByLabelText('Telegram bot token'), 'not-a-token')
    await user.tab()
    await user.clear(screen.getByLabelText('Telegram chat ID'))
    await user.type(screen.getByLabelText('Telegram chat ID'), 'ops')
    await user.tab()

    expect(await screen.findByText('Enter a valid Slack incoming webhook URL')).toBeInTheDocument()
    expect(await screen.findByText('Enter a valid Telegram bot token')).toBeInTheDocument()
    expect(await screen.findByText('Enter a valid Telegram chat ID')).toBeInTheDocument()
  })

  it.each([
    ['an uppercased Slack host', 'https://HOOKS.SLACK.COM/services/T000/B000/secret'],
    ['an explicit default HTTPS port', 'https://hooks.slack.com:443/services/T000/B000/secret'],
    ['a bare query delimiter', 'https://hooks.slack.com/services/T000/B000/secret?'],
    ['a bare fragment delimiter', 'https://hooks.slack.com/services/T000/B000/secret#']
  ])('rejects %s to match backend Slack validation', async (_label, slackWebhookUrl) => {
    const user = userEvent.setup()
    render(<Harness />)

    await user.clear(screen.getByLabelText('Slack webhook URL'))
    await user.type(screen.getByLabelText('Slack webhook URL'), slackWebhookUrl)
    await user.tab()

    expect(await screen.findByText('Enter a valid Slack incoming webhook URL')).toBeInTheDocument()
  })

  it('posts only the selected channel when testing a saved notification channel', async () => {
    const testMutate = vi.fn((_payload, options) => options.onSuccess({ ok: true }))
    ;(useTestNotification as Mock).mockReturnValue({ mutate: testMutate, isPending: false })
    render(<Harness />)

    await userEvent.click(screen.getByRole('button', { name: 'Test Slack notification' }))

    expect(testMutate).toHaveBeenCalledWith(
      { channel: 'slack' },
      expect.objectContaining({ onSuccess: expect.any(Function), onError: expect.any(Function) })
    )
  })

  it('shows a safe success toast after a notification test succeeds', async () => {
    const testMutate = vi.fn((_payload, options) => options.onSuccess({ ok: true }))
    ;(useTestNotification as Mock).mockReturnValue({ mutate: testMutate, isPending: false })
    render(<Harness />)

    await userEvent.click(screen.getByRole('button', { name: 'Test Slack notification' }))

    expect(notify.success).toHaveBeenCalledWith('Test notification sent')
  })

  it('shows a pending test button', () => {
    const testMutate = vi.fn()
    ;(useTestNotification as Mock).mockReturnValue({ mutate: testMutate, isPending: true })

    render(<Harness />)

    const button = screen.getByRole('button', { name: /Test Slack notification/ })
    expect(button).toBeDisabled()
    expect(within(button).getByRole('img', { name: 'loading' })).toBeInTheDocument()
  })

  it('shows a generic error toast when notification testing fails', async () => {
    const testMutate = vi.fn((_payload, options) => options.onSuccess({ ok: false }))
    ;(useTestNotification as Mock).mockReturnValue({ mutate: testMutate, isPending: false })
    render(<Harness />)

    await userEvent.click(screen.getByRole('button', { name: 'Test Telegram notification' }))

    await waitFor(() => expect(notify.error).toHaveBeenCalledWith('Notification test failed'))
  })
})
