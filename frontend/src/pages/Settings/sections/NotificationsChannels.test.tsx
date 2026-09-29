import notify from '@components/Toaster/Toaster'
import {
  type AlertChannel,
  type AlertDeliveryStatus,
  MASK_SENTINEL,
  type MailSettings
} from '@pages/Connections/types'
import useTestNotification from '@pages/Settings/data/useTestNotification'
import { render, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { Form } from 'antd'
import { type Mock, beforeEach, describe, expect, it, vi } from 'vitest'
import NotificationsChannels, {
  type NotificationFormValues,
  storedAlerts,
  toAlertsFormValues,
  toStoredAlerts
} from './NotificationsChannels'

vi.mock('@components/Toaster/Toaster', () => ({
  default: { success: vi.fn(), error: vi.fn() }
}))
vi.mock('@pages/Settings/data/useTestNotification', () => ({ default: vi.fn() }))

const VALID_SLACK_URL = 'https://hooks.slack.com/services/T000/B000/fresh'
const SAVED_WEBHOOK_URL = 'https://example.com/hooks/bit-smtp'
const SAVED_SLACK_URL = 'https://hooks.slack.com/services/T000/B000/saved'
const SAVED_DISCORD_URL = 'https://discord.com/api/webhooks/123/saved'
const CHANNEL_ID_PATTERN = /^ch_[0-9a-f]{12}$/

const allChannels: AlertChannel[] = [
  {
    id: 'ch_000000000001',
    type: 'email',
    name: 'Ops email',
    enabled: true,
    settings: { recipients: ['ops@example.org'] }
  },
  {
    id: 'ch_000000000002',
    type: 'webhook',
    name: 'Webhook',
    enabled: true,
    settings: { url: SAVED_WEBHOOK_URL, signing_secret: MASK_SENTINEL }
  },
  {
    id: 'ch_000000000003',
    type: 'slack',
    name: 'Slack',
    enabled: true,
    settings: { webhook_url: SAVED_SLACK_URL }
  },
  {
    id: 'ch_000000000004',
    type: 'telegram',
    name: 'Telegram',
    enabled: false,
    settings: { bot_token: MASK_SENTINEL, chat_id: '-1001234567890' }
  },
  {
    id: 'ch_000000000005',
    type: 'discord',
    name: 'Discord',
    enabled: true,
    settings: { webhook_url: SAVED_DISCORD_URL }
  }
]

const twoSlackChannels: AlertChannel[] = [
  {
    id: 'ch_00000000000a',
    type: 'slack',
    name: 'Primary Slack',
    enabled: true,
    settings: { webhook_url: SAVED_SLACK_URL }
  },
  {
    id: 'ch_00000000000b',
    type: 'slack',
    name: 'Backup Slack',
    enabled: true,
    settings: { webhook_url: SAVED_SLACK_URL }
  }
]

/** Build a MailSettings fixture whose alerts hold the given channel instances. */
function settingsWith(channels: AlertChannel[], enabled = true): MailSettings {
  return {
    schema_version: 2,
    enabled: true,
    default_connection_id: 'conn_1',
    fallback_connection_ids: [],
    connections: [],
    features: { logging: { enabled: true }, alerts: { enabled, reminder_minutes: 60, channels } }
  }
}

/** Mount NotificationsChannels with a real Form instance, mirroring SettingsPage's wiring. */
function Harness({ settings = settingsWith(allChannels) }: { settings?: MailSettings }) {
  const [alertsForm] = Form.useForm<NotificationFormValues>()

  return (
    <NotificationsChannels
      alertsForm={alertsForm}
      settings={settings}
      alertEvents={<div data-testid="alert-events" />}
    />
  )
}

/** Scope queries to the channel card that contains `element`. */
function cardContaining(element: HTMLElement) {
  const card = element.closest<HTMLElement>('.ant-card')
  if (!card) {
    throw new Error('Element is not inside a channel card')
  }

  return within(card)
}

/** Scope queries to the channel card that contains the field labelled `fieldLabel`. */
function cardOf(fieldLabel: string) {
  return cardContaining(screen.getByLabelText(fieldLabel))
}

/** Every channel card's Name input value, in render order. */
function channelNames(): string[] {
  return screen.getAllByLabelText('Name').map(input => (input as HTMLInputElement).value)
}

/** Add one channel of the given type through the Add channel picker. */
async function addChannel(user: ReturnType<typeof userEvent.setup>, typeLabel: string) {
  await user.click(screen.getByRole('button', { name: 'Add channel' }))
  await user.click(within(await screen.findByRole('dialog')).getByRole('button', { name: typeLabel }))
}

/** Stub the test-notification mutation, resolving each call with `result` (or never, when omitted). */
function mockTestMutation(result?: { ok: boolean; error?: string }) {
  const mutate = vi.fn((_payload, options) => {
    if (result) {
      options.onSuccess(result)
      options.onSettled()
    }
  })
  ;(useTestNotification as Mock).mockReturnValue({ mutate, isPending: false })

  return mutate
}

describe('NotificationsChannels', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    mockTestMutation({ ok: true })
  })

  it('renders one card per stored channel instance', () => {
    render(<Harness />)

    expect(channelNames()).toEqual(['Ops email', 'Webhook', 'Slack', 'Telegram', 'Discord'])
    expect(screen.getAllByRole('switch', { name: /^Turn on / })).toHaveLength(5)
  })

  it('shows the empty state when no channel has been added', () => {
    render(<Harness settings={settingsWith([])} />)

    expect(screen.getByText('No notification channels yet')).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Add channel' })).toBeInTheDocument()
    expect(screen.queryByLabelText('Name')).not.toBeInTheDocument()
  })

  it('offers every channel type in the Add channel picker, even ones already added', async () => {
    const user = userEvent.setup()
    render(<Harness />)

    await user.click(screen.getByRole('button', { name: 'Add channel' }))
    const picker = within(await screen.findByRole('dialog'))

    ;['Email', 'Webhook', 'Slack', 'Telegram', 'Discord'].forEach(label =>
      expect(picker.getByRole('button', { name: label })).toBeInTheDocument()
    )
  })

  it('names two added Slack channels "Slack" and "Slack 2"', async () => {
    const user = userEvent.setup()
    render(<Harness settings={settingsWith([])} />)

    await addChannel(user, 'Slack')
    await addChannel(user, 'Slack')

    expect(channelNames()).toEqual(['Slack', 'Slack 2'])
    expect(screen.getAllByLabelText('Slack webhook URL')).toHaveLength(2)
  })

  it('adds a blank, enabled card after picking a type', async () => {
    const user = userEvent.setup()
    render(<Harness settings={settingsWith([])} />)

    await addChannel(user, 'Email')

    expect(screen.getByLabelText('Recipients')).toBeInTheDocument()
    expect(screen.getByRole('switch', { name: 'Turn on Email' })).toBeChecked()
  })

  it('removes only the confirmed card', async () => {
    const user = userEvent.setup()
    render(<Harness settings={settingsWith(twoSlackChannels)} />)

    const [primaryName] = screen.getAllByLabelText('Name')
    await user.click(cardContaining(primaryName).getByRole('button', { name: /remove/i }))
    await user.click(
      within(await screen.findByRole('tooltip')).getByRole('button', { name: 'Remove channel' })
    )

    await waitFor(() => expect(channelNames()).toEqual(['Backup Slack']))
  })

  it('keeps channel fields editable while failure notifications are off', async () => {
    const user = userEvent.setup()
    render(<Harness />)

    await user.click(screen.getByRole('switch', { name: 'Send alerts' }))

    expect(screen.getByLabelText('Slack webhook URL')).toBeEnabled()
    expect(screen.getByRole('button', { name: 'Add channel' })).toBeEnabled()
    screen.getAllByRole('button', { name: /send test/i }).forEach(button => expect(button).toBeEnabled())
  })

  it('generates a whsec signing secret', async () => {
    const user = userEvent.setup()
    render(<Harness />)

    await user.click(screen.getByRole('button', { name: /generate signing secret/i }))

    expect((screen.getByLabelText('Signing secret') as HTMLInputElement).value).toMatch(
      /^whsec_[a-f0-9]{64}$/
    )
  })

  it('shows a validation error when an email channel loses its recipients', async () => {
    const user = userEvent.setup()
    render(<Harness />)

    await user.click(screen.getByLabelText('Recipients'))
    await user.keyboard('{Backspace}')

    expect(await screen.findByText('Add at least one recipient')).toBeInTheDocument()
  })

  it('hydrates saved secrets as masked password fields', () => {
    render(<Harness />)

    expect(screen.getByLabelText('Signing secret')).toHaveValue(MASK_SENTINEL)
    expect(screen.getByLabelText('Signing secret')).toHaveAttribute('type', 'password')
    expect(screen.getByLabelText('Telegram bot token')).toHaveValue(MASK_SENTINEL)
    expect(screen.getByLabelText('Telegram bot token')).toHaveAttribute('type', 'password')
    expect(screen.getByLabelText('Telegram chat ID')).toHaveValue('-1001234567890')
  })

  it('shows saved destination URLs as visible, unmasked URL inputs', () => {
    render(<Harness />)
    ;(
      [
        ['Webhook URL', SAVED_WEBHOOK_URL],
        ['Slack webhook URL', SAVED_SLACK_URL],
        ['Discord webhook URL', SAVED_DISCORD_URL]
      ] as const
    ).forEach(([label, url]) => {
      const input = screen.getByLabelText(label)
      expect(input).toHaveValue(url)
      expect(input).toHaveAttribute('type', 'url')
      expect(input).toHaveAttribute('inputmode', 'url')
    })
  })

  it('hides the reveal toggle while a saved secret is masked and restores it once edited', async () => {
    const user = userEvent.setup()
    render(<Harness />)

    expect(screen.queryAllByRole('img', { name: 'eye-invisible' })).toHaveLength(0)

    const botToken = screen.getByLabelText('Telegram bot token')
    await user.clear(botToken)
    await user.type(botToken, '123456:abcdefghijklmnopqrstuvwxyz')

    expect(await screen.findByRole('img', { name: 'eye-invisible' })).toBeInTheDocument()
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

  it('shows an inline validation error for an invalid Discord webhook URL', async () => {
    const user = userEvent.setup()
    render(<Harness />)

    await user.clear(screen.getByLabelText('Discord webhook URL'))
    await user.type(
      screen.getByLabelText('Discord webhook URL'),
      'https://discord.com/webhooks/not-a-webhook'
    )
    await user.tab()

    expect(await screen.findByText('Enter a valid Discord webhook URL')).toBeInTheDocument()
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

  it("posts an unsaved card's live, trimmed values with its id and type on Send test", async () => {
    const user = userEvent.setup()
    const mutate = mockTestMutation({ ok: true })
    render(<Harness settings={settingsWith([])} />)

    await addChannel(user, 'Slack')
    await user.type(screen.getByLabelText('Slack webhook URL'), `  ${VALID_SLACK_URL}  `)
    await user.click(cardOf('Slack webhook URL').getByRole('button', { name: /send test/i }))

    await waitFor(() =>
      expect(mutate).toHaveBeenCalledWith(
        {
          channel: {
            id: expect.stringMatching(CHANNEL_ID_PATTERN),
            type: 'slack',
            settings: { webhook_url: VALID_SLACK_URL }
          }
        },
        expect.objectContaining({ onSuccess: expect.any(Function), onError: expect.any(Function) })
      )
    )
    expect(notify.success).toHaveBeenCalledWith('Test notification sent')
  })

  it('posts a saved channel with its masked secret so the backend resolves the stored value', async () => {
    const user = userEvent.setup()
    const mutate = mockTestMutation({ ok: true })
    render(<Harness />)

    await user.click(cardOf('Signing secret').getByRole('button', { name: /send test/i }))

    await waitFor(() =>
      expect(mutate).toHaveBeenCalledWith(
        {
          channel: {
            id: 'ch_000000000002',
            type: 'webhook',
            settings: { url: SAVED_WEBHOOK_URL, signing_secret: MASK_SENTINEL }
          }
        },
        expect.anything()
      )
    )
  })

  it('does not send a test when the card has invalid fields', async () => {
    const user = userEvent.setup()
    const mutate = mockTestMutation({ ok: true })
    render(<Harness settings={settingsWith([])} />)

    await addChannel(user, 'Slack')
    await user.click(cardOf('Slack webhook URL').getByRole('button', { name: /send test/i }))

    expect(await screen.findByText('Enter a valid Slack incoming webhook URL')).toBeInTheDocument()
    expect(mutate).not.toHaveBeenCalled()
  })

  it("shows the server's error message when a test is rejected", async () => {
    const user = userEvent.setup()
    mockTestMutation({ ok: false, error: 'Slack rejected the webhook.' })
    render(<Harness />)

    await user.click(cardOf('Slack webhook URL').getByRole('button', { name: /send test/i }))

    await waitFor(() => expect(notify.error).toHaveBeenCalledWith('Slack rejected the webhook.'))
  })

  it('falls back to a generic error toast when a rejected test has no message', async () => {
    const user = userEvent.setup()
    mockTestMutation({ ok: false })
    render(<Harness />)

    await user.click(cardOf('Telegram bot token').getByRole('button', { name: /send test/i }))

    await waitFor(() => expect(notify.error).toHaveBeenCalledWith('Notification test failed'))
  })

  it('shows the in-flight card as loading and disables the other cards’ Send test', async () => {
    const user = userEvent.setup()
    mockTestMutation()
    render(<Harness />)

    const slackTest = cardOf('Slack webhook URL').getByRole('button', { name: /send test/i })
    await user.click(slackTest)

    await waitFor(() =>
      expect(within(slackTest).getByRole('img', { name: 'loading' })).toBeInTheDocument()
    )
    expect(cardOf('Discord webhook URL').getByRole('button', { name: /send test/i })).toBeDisabled()
  })
})

describe('delivery warnings', () => {
  const CRON_DISABLED = /WP-Cron\) are off on this site/
  const OVERDUE = /Some alerts are waiting to go out/

  /** Settings whose alerts carry the given server-reported delivery status. */
  function settingsWithDelivery(delivery: AlertDeliveryStatus, enabled = true): MailSettings {
    const settings = settingsWith([], enabled)

    return {
      ...settings,
      features: { alerts: { enabled, reminder_minutes: 60, channels: [], delivery } }
    }
  }

  it('warns that WP-Cron is disabled when alerts are on', () => {
    render(<Harness settings={settingsWithDelivery({ cron_disabled: true, overdue: false })} />)

    expect(screen.getByRole('alert')).toHaveTextContent(CRON_DISABLED)
    expect(screen.queryByText(OVERDUE)).not.toBeInTheDocument()
  })

  it('warns that alerts are overdue when WP-Cron has not run recently', () => {
    render(<Harness settings={settingsWithDelivery({ cron_disabled: false, overdue: true })} />)

    expect(screen.getByRole('alert')).toHaveTextContent(OVERDUE)
    expect(screen.queryByText(CRON_DISABLED)).not.toBeInTheDocument()
  })

  it('shows no warning while delivery is healthy', () => {
    render(<Harness settings={settingsWithDelivery({ cron_disabled: false, overdue: false })} />)

    expect(screen.queryByRole('alert')).not.toBeInTheDocument()
  })

  it('hides the warnings while the alerts master switch is off, and shows them once it is turned on', async () => {
    const user = userEvent.setup()
    render(<Harness settings={settingsWithDelivery({ cron_disabled: true, overdue: true }, false)} />)

    expect(screen.queryByRole('alert')).not.toBeInTheDocument()

    await user.click(screen.getByRole('switch', { name: 'Send alerts' }))

    expect(await screen.findAllByRole('alert')).toHaveLength(2)
  })
})

describe('toAlertsFormValues', () => {
  it('reads the pre-seed [] alerts blob as disabled with no channels', () => {
    const settings: MailSettings = { ...settingsWith([]), features: { alerts: [] } }

    expect(toAlertsFormValues(settings)).toEqual({ enabled: false, reminder_minutes: 60, channels: [] })
  })
})

describe('storedAlerts', () => {
  it('exposes the server revision and delivery metadata without leaking them into form values', () => {
    const settings: MailSettings = {
      ...settingsWith([]),
      features: {
        alerts: {
          enabled: true,
          reminder_minutes: 60,
          channels: [],
          revision: 'rev-1',
          delivery: { cron_disabled: false, overdue: true }
        }
      }
    }

    expect(storedAlerts(settings)?.revision).toBe('rev-1')
    expect(toAlertsFormValues(settings)).toEqual({ enabled: true, reminder_minutes: 60, channels: [] })
  })

  it('is undefined for the pre-seed [] alerts blob', () => {
    expect(storedAlerts({ ...settingsWith([]), features: { alerts: [] } })).toBeUndefined()
  })
})

describe('reminder interval', () => {
  it('defaults to hourly reminders when the stored alerts predate the setting', () => {
    const settings = settingsWith([])
    const withoutReminder = {
      enabled: true,
      channels: []
    } as unknown as MailSettings['features']['alerts']

    expect(
      toAlertsFormValues({ ...settings, features: { alerts: withoutReminder } }).reminder_minutes
    ).toBe(60)
  })
})

describe('what triggers an alert', () => {
  it('shows the alert checklist and repeat control only while alerts are on', async () => {
    const user = userEvent.setup()
    render(<Harness settings={settingsWith([], false)} />)

    expect(screen.getByTestId('alert-events')).not.toBeVisible()
    expect(screen.getByText('Repeat while the problem continues')).not.toBeVisible()

    await user.click(screen.getByRole('switch', { name: 'Send alerts' }))

    expect(screen.getByTestId('alert-events')).toBeVisible()
    expect(screen.getByText('Repeat while the problem continues')).toBeVisible()
  })

  it('offers “Don’t repeat” for the shared repeat interval', async () => {
    const user = userEvent.setup()
    render(<Harness settings={settingsWith([])} />)

    await user.click(screen.getByLabelText('Repeat while the problem continues'))

    expect(await screen.findByText('Don’t repeat')).toBeInTheDocument()
  })
})

describe('toStoredAlerts', () => {
  it('trims names, string settings, and recipients, dropping blank recipients', () => {
    const stored = toStoredAlerts({
      enabled: true,
      reminder_minutes: 60,
      channels: [
        {
          id: 'ch_000000000001',
          type: 'email',
          name: '  Ops email ',
          enabled: true,
          settings: { recipients: [' ops@example.org ', '  '] }
        },
        {
          id: 'ch_000000000002',
          type: 'telegram',
          name: 'Telegram',
          enabled: false,
          settings: { bot_token: ' 123456:abcdefghijklmnopqrstuvwxyz ', chat_id: ' -100123 ' }
        }
      ]
    })

    expect(stored).toEqual({
      enabled: true,
      reminder_minutes: 60,
      channels: [
        {
          id: 'ch_000000000001',
          type: 'email',
          name: 'Ops email',
          enabled: true,
          settings: { recipients: ['ops@example.org'] }
        },
        {
          id: 'ch_000000000002',
          type: 'telegram',
          name: 'Telegram',
          enabled: false,
          settings: { bot_token: '123456:abcdefghijklmnopqrstuvwxyz', chat_id: '-100123' }
        }
      ]
    })
  })
})
