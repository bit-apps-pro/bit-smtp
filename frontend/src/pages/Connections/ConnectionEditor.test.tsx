import { type Connection, type ProviderMeta } from '@pages/Connections/types'
import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { type Mock, beforeEach, describe, expect, it, vi } from 'vitest'
import ConnectionEditor from './ConnectionEditor'
import useSaveConnection from './data/useSaveConnection'
import useTestConnection from './data/useTestConnection'

vi.mock('./data/useSaveConnection', () => ({ default: vi.fn() }))
vi.mock('./data/useTestConnection', () => ({ default: vi.fn() }))

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
      key: 'encryption',
      label: 'Encryption',
      type: 'select',
      required: true,
      secret: false,
      placeholder: '',
      default: 'tls',
      options: [
        { value: 'none', label: 'None' },
        { value: 'ssl', label: 'SSL' },
        { value: 'tls', label: 'TLS' }
      ],
      dependsOn: null
    },
    {
      key: 'auth',
      label: 'Authentication',
      type: 'switch',
      required: false,
      secret: false,
      placeholder: '',
      default: true,
      options: [],
      dependsOn: null
    },
    {
      key: 'username',
      label: 'Username',
      type: 'text',
      required: false,
      secret: false,
      placeholder: '',
      default: '',
      options: [],
      dependsOn: { field: 'auth', value: true }
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
      dependsOn: { field: 'auth', value: true }
    }
  ]
}

const connection: Connection = {
  id: 'conn_1',
  provider: 'other_smtp',
  kind: 'smtp',
  name: 'Primary',
  enabled: true,
  fromEmail: 'a@b.c',
  fromName: 'A',
  replyToEmail: '',
  settings: { host: 'smtp.x', port: 587, encryption: 'tls', auth: true, username: 'u' },
  credentials: { password: { source: 'database', value: '********' } }
}

describe('ConnectionEditor', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    ;(useTestConnection as Mock).mockReturnValue({ mutate: vi.fn(), isPending: false, data: undefined })
  })

  it('saves a v2 connection payload; untouched password stays sentinel', async () => {
    const save = vi.fn().mockResolvedValue({})
    ;(useSaveConnection as Mock).mockReturnValue({ mutateAsync: save, isPending: false })

    render(<ConnectionEditor connection={connection} provider={otherSmtpMeta} onSaved={() => {}} />)

    await userEvent.click(screen.getByRole('button', { name: /save/i }))

    expect(save).toHaveBeenCalledWith(
      expect.objectContaining({
        id: 'conn_1',
        provider: 'other_smtp',
        kind: 'smtp',
        name: 'Primary',
        fromEmail: 'a@b.c',
        settings: expect.objectContaining({ host: 'smtp.x', encryption: 'tls' }),
        credentials: { password: { source: 'database', value: '********' } }
      })
    )
  })

  it('calls onSaved after a successful save', async () => {
    const save = vi.fn().mockResolvedValue({})
    ;(useSaveConnection as Mock).mockReturnValue({ mutateAsync: save, isPending: false })
    const onSaved = vi.fn()

    render(<ConnectionEditor connection={connection} provider={otherSmtpMeta} onSaved={onSaved} />)

    await userEvent.click(screen.getByRole('button', { name: /save/i }))

    expect(onSaved).toHaveBeenCalled()
  })

  it('submits an edited password as the new value', async () => {
    const save = vi.fn().mockResolvedValue({})
    ;(useSaveConnection as Mock).mockReturnValue({ mutateAsync: save, isPending: false })

    render(<ConnectionEditor connection={connection} provider={otherSmtpMeta} onSaved={() => {}} />)

    await userEvent.clear(screen.getByLabelText('Password'))
    await userEvent.type(screen.getByLabelText('Password'), 'newsecret')
    await userEvent.click(screen.getByRole('button', { name: /save/i }))

    expect(save).toHaveBeenCalledWith(
      expect.objectContaining({
        credentials: { password: { source: 'database', value: 'newsecret' } }
      })
    )
  })
})
