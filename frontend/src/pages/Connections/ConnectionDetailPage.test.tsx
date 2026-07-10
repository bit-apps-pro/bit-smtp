import type * as ReactRouterDom from 'react-router-dom'
import { renderWithProviders } from '@config/test-utils'
import { type MailSettings, type ProviderMeta } from '@pages/Connections/types'
import { screen } from '@testing-library/react'
import { type Mock, beforeEach, describe, expect, it, vi } from 'vitest'
import ConnectionDetailPage from './ConnectionDetailPage'
import useMailSettings from './data/useMailSettings'
import useProviders from './data/useProviders'

vi.mock('./data/useMailSettings', () => ({ default: vi.fn() }))
vi.mock('./data/useProviders', () => ({ default: vi.fn() }))

let paramId = 'conn_2'
vi.mock('react-router-dom', async importOriginal => ({
  ...(await importOriginal<typeof ReactRouterDom>()),
  useParams: () => ({ id: paramId })
}))

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
    }
  ]
}

const settings: MailSettings = {
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
      settings: { host: 'smtp.one' },
      credentials: {}
    },
    {
      id: 'conn_2',
      provider: 'other_smtp',
      kind: 'smtp',
      name: 'Backup SMTP',
      enabled: true,
      fromEmail: 'd@e.f',
      fromName: 'D',
      replyToEmail: '',
      settings: { host: 'smtp.two' },
      credentials: {}
    }
  ],
  features: {}
}

describe('ConnectionDetailPage', () => {
  beforeEach(() => {
    ;(useMailSettings as Mock).mockReturnValue({ data: settings, isPending: false })
    ;(useProviders as Mock).mockReturnValue({ data: [otherSmtpMeta], isPending: false })
  })

  it('renders the editor pre-filled with the connection matching the :id param', () => {
    paramId = 'conn_2'
    renderWithProviders(<ConnectionDetailPage />)

    expect(screen.getByLabelText('Name')).toHaveValue('Backup SMTP')
    expect(screen.getByLabelText('SMTP Host')).toHaveValue('smtp.two')
  })

  it('shows a loading spinner while settings are pending', () => {
    paramId = 'conn_2'
    ;(useMailSettings as Mock).mockReturnValue({ data: undefined, isPending: true })

    const { container } = renderWithProviders(<ConnectionDetailPage />)

    expect(container.querySelector('.ant-spin')).toBeInTheDocument()
  })

  it('shows a not-found message with a back link for an unknown id', () => {
    paramId = 'does-not-exist'
    renderWithProviders(<ConnectionDetailPage />)

    expect(screen.getByText('Connection not found')).toBeInTheDocument()
    expect(screen.getByRole('link', { name: 'Back to connections' })).toBeInTheDocument()
  })
})
