import { renderWithProviders } from '@config/test-utils'
import { type MailSettings, type ProviderMeta } from '@pages/Connections/types'
import { screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { type Mock, describe, expect, it, vi } from 'vitest'
import ConnectionsPage from './ConnectionsPage'
import useMailSettings from './data/useMailSettings'
import useProviders from './data/useProviders'

vi.mock('./data/useMailSettings', () => ({ default: vi.fn() }))
vi.mock('./data/useProviders', () => ({ default: vi.fn() }))

const otherSmtpMeta: ProviderMeta = {
  key: 'other_smtp',
  label: 'Other SMTP',
  kind: 'smtp',
  fields: [
    {
      key: 'host',
      label: 'SMTP Host',
      type: 'text',
      required: true,
      secret: false,
      placeholder: 'smtp.example.com',
      default: '',
      options: [],
      dependsOn: null
    },
    {
      key: 'port',
      label: 'SMTP Port',
      type: 'number',
      required: true,
      secret: false,
      placeholder: '',
      default: 587,
      options: [],
      dependsOn: null
    },
    {
      key: 'password',
      label: 'Password',
      type: 'password',
      required: false,
      secret: true,
      placeholder: '',
      default: '',
      options: [],
      dependsOn: null
    }
  ]
}

const settingsWithOneConn: MailSettings = {
  schema_version: 2,
  enabled: true,
  default_connection_id: 'conn_1',
  fallback_connection_ids: [],
  connections: [
    {
      id: 'conn_1',
      provider: 'other_smtp',
      kind: 'smtp',
      name: 'Primary SMTP',
      enabled: true,
      fromEmail: 'a@b.c',
      fromName: 'A',
      replyToEmail: '',
      settings: { host: 'smtp.x', port: 587 },
      credentials: { password: { source: 'database', value: '********' } }
    }
  ],
  features: {}
}

describe('ConnectionsPage', () => {
  it('lists connections and edits the selected one', async () => {
    ;(useMailSettings as Mock).mockReturnValue({ data: settingsWithOneConn, isPending: false })
    ;(useProviders as Mock).mockReturnValue({ data: [otherSmtpMeta], isPending: false })

    renderWithProviders(<ConnectionsPage />)

    const connectionButton = screen.getByRole('button', { name: 'Primary SMTP' })
    expect(connectionButton).toBeInTheDocument()

    await userEvent.click(connectionButton)

    expect(screen.getByLabelText('SMTP Host')).toHaveValue('smtp.x')
  })
})
