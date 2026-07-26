import useMailSettings from '@pages/Connections/data/useMailSettings'
import useUpdateSettings from '@pages/Connections/data/useUpdateSettings'
import { type MailSettings } from '@pages/Connections/types'
import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { type Mock, beforeEach, describe, expect, it, vi } from 'vitest'
import NotificationsPage from './NotificationsPage'

vi.mock('@pages/Connections/data/useMailSettings', () => ({ default: vi.fn() }))
vi.mock('@pages/Connections/data/useUpdateSettings', () => ({ default: vi.fn() }))
vi.mock('@components/Toaster/Toaster', () => ({
  default: { success: vi.fn(), error: vi.fn() }
}))

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
})
