import notify from '@components/Toaster/Toaster'
import useMailSettings from '@pages/Connections/data/useMailSettings'
import useUpdateSettings from '@pages/Connections/data/useUpdateSettings'
import { type MailSettings } from '@pages/Connections/types'
import { render, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { type Mock, beforeEach, describe, expect, it, vi } from 'vitest'
import NotificationsPage from './NotificationsPage'
import useTestNotification from './data/useTestNotification'

vi.mock('@pages/Connections/data/useMailSettings', () => ({ default: vi.fn() }))
vi.mock('@pages/Connections/data/useUpdateSettings', () => ({ default: vi.fn() }))
vi.mock('@components/Toaster/Toaster', () => ({
  default: { success: vi.fn(), error: vi.fn() }
}))
vi.mock('./data/useTestNotification', () => ({ default: vi.fn() }))

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

describe('NotificationsPage', () => {
  const mutate = vi.fn()

  beforeEach(() => {
    vi.clearAllMocks()
    ;(useMailSettings as Mock).mockReturnValue({ data: settings, isPending: false })
    ;(useUpdateSettings as Mock).mockReturnValue({ mutate, isPending: false })
    ;(useTestNotification as Mock).mockReturnValue({ mutate: vi.fn(), isPending: false })
  })

  it('saves notification settings while preserving other features', async () => {
    render(<NotificationsPage />)

    await userEvent.click(screen.getByRole('button', { name: /^save$/i }))

    await waitFor(() => {
      expect(mutate).toHaveBeenCalledWith(
        {
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
        },
        expect.objectContaining({ onSuccess: expect.any(Function) })
      )
    })
  })

  it('generates a whsec signing secret', async () => {
    render(<NotificationsPage />)

    await userEvent.click(screen.getByRole('button', { name: /generate signing secret/i }))

    expect((screen.getByLabelText('Signing secret') as HTMLInputElement).value).toMatch(
      /^whsec_[a-f0-9]{64}$/
    )
  })

  it('blocks saving when an enabled email channel has no recipients', async () => {
    const existingAlerts = settings.features.alerts
    if (!existingAlerts) {
      throw new Error('Expected alert settings fixture')
    }

    const noRecipients: MailSettings = {
      ...settings,
      features: {
        ...settings.features,
        alerts: {
          ...existingAlerts,
          email: { enabled: true, recipients: [] }
        }
      }
    }
    ;(useMailSettings as Mock).mockReturnValue({ data: noRecipients, isPending: false })

    render(<NotificationsPage />)
    await userEvent.click(screen.getByRole('button', { name: /^save$/i }))

    expect(await screen.findByText('Add at least one recipient')).toBeInTheDocument()
    expect(mutate).not.toHaveBeenCalled()
  })

  it('hydrates saved Slack and Telegram secrets as masked password fields', () => {
    render(<NotificationsPage />)

    expect((screen.getByLabelText('Slack webhook URL') as HTMLInputElement).value).toBe('********')
    expect((screen.getByLabelText('Telegram bot token') as HTMLInputElement).value).toBe('********')
    expect((screen.getByLabelText('Telegram chat ID') as HTMLInputElement).value).toBe('-1001234567890')
  })

  it('defaults the Slack and Telegram sections when legacy alerts omit them', () => {
    const legacySettings: MailSettings = {
      ...settings,
      features: {
        ...settings.features,
        alerts: {
          enabled: true,
          email: { enabled: false, recipients: [] },
          webhook: { enabled: false, url: '', signing_secret: '' }
        } as unknown as MailSettings['features']['alerts']
      }
    }
    ;(useMailSettings as Mock).mockReturnValue({ data: legacySettings, isPending: false })

    render(<NotificationsPage />)

    expect(screen.getByRole('switch', { name: 'Slack notification' })).not.toBeChecked()
    expect(screen.getByRole('switch', { name: 'Telegram notification' })).not.toBeChecked()
    expect(screen.getByLabelText('Slack webhook URL')).toHaveValue('')
    expect(screen.getByLabelText('Telegram bot token')).toHaveValue('')
    expect(screen.getByLabelText('Telegram chat ID')).toHaveValue('')
  })

  it('keeps channel controls disabled until failure and channel notifications are enabled', async () => {
    const user = userEvent.setup()
    render(<NotificationsPage />)

    const slackUrl = screen.getByLabelText('Slack webhook URL')
    expect(slackUrl).not.toBeDisabled()

    await user.click(screen.getByRole('switch', { name: 'Failure notifications' }))

    expect(slackUrl).toBeDisabled()
    expect(screen.getByRole('button', { name: 'Test Slack notification' })).toBeDisabled()
    expect(screen.getByRole('button', { name: 'Test Telegram notification' })).toBeDisabled()
  })

  it('blocks saving invalid Slack and Telegram configuration', async () => {
    const user = userEvent.setup()
    render(<NotificationsPage />)

    await user.clear(screen.getByLabelText('Slack webhook URL'))
    await user.type(screen.getByLabelText('Slack webhook URL'), 'https://example.com/not-slack')
    await user.clear(screen.getByLabelText('Telegram bot token'))
    await user.type(screen.getByLabelText('Telegram bot token'), 'not-a-token')
    await user.clear(screen.getByLabelText('Telegram chat ID'))
    await user.type(screen.getByLabelText('Telegram chat ID'), 'ops')
    await user.click(screen.getByRole('button', { name: /^save$/i }))

    expect(await screen.findByText('Enter a valid Slack incoming webhook URL')).toBeInTheDocument()
    expect(await screen.findByText('Enter a valid Telegram bot token')).toBeInTheDocument()
    expect(await screen.findByText('Enter a valid Telegram chat ID')).toBeInTheDocument()
    expect(mutate).not.toHaveBeenCalled()
  })

  it.each([
    ['an uppercased Slack host', 'https://HOOKS.SLACK.COM/services/T000/B000/secret'],
    ['an explicit default HTTPS port', 'https://hooks.slack.com:443/services/T000/B000/secret']
  ])('rejects %s to match backend Slack validation', async (_label, slackWebhookUrl) => {
    const user = userEvent.setup()
    render(<NotificationsPage />)

    await user.clear(screen.getByLabelText('Slack webhook URL'))
    await user.type(screen.getByLabelText('Slack webhook URL'), slackWebhookUrl)
    await user.click(screen.getByRole('button', { name: /^save$/i }))

    expect(await screen.findByText('Enter a valid Slack incoming webhook URL')).toBeInTheDocument()
    expect(mutate).not.toHaveBeenCalled()
  })

  it('posts only the selected channel when testing a saved notification channel', async () => {
    const testMutate = vi.fn((_payload, options) => options.onSuccess({ ok: true }))
    ;(useTestNotification as Mock).mockReturnValue({ mutate: testMutate, isPending: false })
    render(<NotificationsPage />)

    await userEvent.click(screen.getByRole('button', { name: 'Test Slack notification' }))

    expect(testMutate).toHaveBeenCalledWith(
      { channel: 'slack' },
      expect.objectContaining({ onSuccess: expect.any(Function), onError: expect.any(Function) })
    )
  })

  it('shows a safe success toast after a notification test succeeds', async () => {
    const testMutate = vi.fn((_payload, options) => options.onSuccess({ ok: true }))
    ;(useTestNotification as Mock).mockReturnValue({ mutate: testMutate, isPending: false })
    render(<NotificationsPage />)

    await userEvent.click(screen.getByRole('button', { name: 'Test Slack notification' }))

    expect(notify.success).toHaveBeenCalledWith('Test notification sent')
  })

  it('shows a pending test button', () => {
    const testMutate = vi.fn()
    ;(useTestNotification as Mock).mockReturnValue({ mutate: testMutate, isPending: true })

    render(<NotificationsPage />)

    const button = screen.getByRole('button', { name: /Test Slack notification/ })
    expect(button).toBeDisabled()
    expect(within(button).getByRole('img', { name: 'loading' })).toBeInTheDocument()
  })

  it('shows a generic error toast when notification testing fails', async () => {
    const testMutate = vi.fn((_payload, options) => options.onSuccess({ ok: false }))
    ;(useTestNotification as Mock).mockReturnValue({ mutate: testMutate, isPending: false })
    render(<NotificationsPage />)

    await userEvent.click(screen.getByRole('button', { name: 'Test Telegram notification' }))

    expect(notify.error).toHaveBeenCalledWith('Notification test failed')
  })
})
