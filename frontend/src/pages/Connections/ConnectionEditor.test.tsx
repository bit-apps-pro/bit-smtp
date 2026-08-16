import { type ReactNode } from 'react'
import { type Connection, type ProviderMeta } from '@pages/Connections/types'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { type Mock, beforeEach, describe, expect, it, vi } from 'vitest'
import ConnectionEditor from './ConnectionEditor'
import useOAuthAuthorize from './data/useOAuthAuthorize'
import useSaveConnection from './data/useSaveConnection'
import useTestConnection from './data/useTestConnection'

vi.mock('./data/useSaveConnection', () => ({ default: vi.fn() }))
vi.mock('./data/useTestConnection', () => ({ default: vi.fn() }))
vi.mock('./data/useOAuthAuthorize', () => ({ default: vi.fn() }))

function renderWithQueryClient(ui: React.ReactElement) {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  function wrapper({ children }: { children: ReactNode }) {
    return <QueryClientProvider client={client}>{children}</QueryClientProvider>
  }
  return render(ui, { wrapper })
}

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

const gmailMeta: ProviderMeta = {
  key: 'gmail',
  label: 'Gmail / Google Workspace',
  kind: 'api',
  supports_webhook: false,
  fields: [
    {
      key: 'client_id',
      label: 'Client ID',
      type: 'text',
      required: true,
      secret: false,
      placeholder: '',
      default: '',
      options: [],
      dependsOn: null
    },
    {
      key: 'client_secret',
      label: 'Client Secret',
      type: 'password',
      required: false,
      secret: true,
      placeholder: '',
      default: '',
      options: [],
      dependsOn: null
    },
    {
      key: 'oauth',
      label: 'Google account',
      type: 'oauth',
      required: false,
      secret: false,
      placeholder: '',
      default: '',
      options: [],
      dependsOn: null
    }
  ]
}

const gmailConnection: Connection = {
  id: 'conn_gmail',
  provider: 'gmail',
  kind: 'api',
  name: 'Gmail',
  enabled: true,
  fromEmail: 'a@b.c',
  fromName: 'A',
  replyToEmail: '',
  settings: { client_id: 'abc.apps.googleusercontent.com' },
  credentials: { client_secret: { source: 'database', value: '********' } }
}

const sendGridMeta: ProviderMeta = {
  key: 'sendgrid',
  label: 'SendGrid',
  kind: 'api',
  supports_webhook: true,
  fields: [
    {
      key: 'region',
      label: 'Region',
      type: 'select',
      required: false,
      secret: false,
      placeholder: '',
      default: 'global',
      options: [
        { value: 'global', label: 'Global' },
        { value: 'eu', label: 'EU' }
      ],
      dependsOn: null
    },
    {
      key: 'api_key',
      label: 'API Key',
      type: 'password',
      required: true,
      secret: true,
      placeholder: '',
      default: '',
      options: [],
      dependsOn: null
    }
  ]
}

const sendGridConnection: Connection = {
  id: 'conn_sendgrid',
  provider: 'sendgrid',
  kind: 'api',
  name: 'SendGrid',
  enabled: true,
  fromEmail: 'a@b.c',
  fromName: 'A',
  replyToEmail: '',
  settings: { region: 'global' },
  credentials: { api_key: { source: 'database', value: '********' } }
}

const phpSendmailMeta: ProviderMeta = {
  key: 'php_sendmail',
  label: 'PHP Sendmail',
  kind: 'local',
  fields: []
}

const phpSendmailConnection: Connection = {
  id: 'conn_php_sendmail',
  provider: 'php_sendmail',
  kind: 'local',
  name: 'Server mail',
  enabled: true,
  fromEmail: 'sender@example.org',
  fromName: 'Sender',
  replyToEmail: '',
  settings: {},
  credentials: {}
}

describe('ConnectionEditor', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    ;(useTestConnection as Mock).mockReturnValue({ mutate: vi.fn(), isPending: false, data: undefined })
  })

  it('renders the test result above the sticky action bar, not nested inside it (#32)', () => {
    ;(useSaveConnection as Mock).mockReturnValue({ mutateAsync: vi.fn(), isPending: false })
    ;(useTestConnection as Mock).mockReturnValue({
      mutate: vi.fn(),
      isPending: false,
      data: { ok: true, debug: ['Connected'], delivery: null }
    })

    render(<ConnectionEditor connection={connection} provider={otherSmtpMeta} onSaved={() => {}} />)

    const alert = screen.getByRole('alert')
    const actionsBar = screen.getByTestId('connection-actions-bar')

    expect(actionsBar).not.toContainElement(alert)
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

  it('seeds a provider-specific secret field (SendGrid api_key) from its own credential key', () => {
    ;(useSaveConnection as Mock).mockReturnValue({ mutateAsync: vi.fn(), isPending: false })

    render(
      <ConnectionEditor connection={sendGridConnection} provider={sendGridMeta} onSaved={() => {}} />
    )

    expect(screen.getByLabelText('API Key')).toHaveValue('********')
  })

  it('saves an untouched SendGrid api_key under credentials.api_key, not credentials.password', async () => {
    const save = vi.fn().mockResolvedValue({})
    ;(useSaveConnection as Mock).mockReturnValue({ mutateAsync: save, isPending: false })

    render(
      <ConnectionEditor connection={sendGridConnection} provider={sendGridMeta} onSaved={() => {}} />
    )

    await userEvent.click(screen.getByRole('button', { name: /save/i }))

    expect(save).toHaveBeenCalledWith(
      expect.objectContaining({
        settings: { region: 'global', webhook_enabled: true },
        credentials: { api_key: { source: 'database', value: '********' } }
      })
    )
    const [payload] = save.mock.calls[0] as [Connection]
    expect(payload.credentials).not.toHaveProperty('password')
  })

  it('submits an edited SendGrid api_key as the new value under credentials.api_key', async () => {
    const save = vi.fn().mockResolvedValue({})
    ;(useSaveConnection as Mock).mockReturnValue({ mutateAsync: save, isPending: false })

    render(
      <ConnectionEditor connection={sendGridConnection} provider={sendGridMeta} onSaved={() => {}} />
    )

    await userEvent.clear(screen.getByLabelText('API Key'))
    await userEvent.type(screen.getByLabelText('API Key'), 'sg-newkey')
    await userEvent.click(screen.getByRole('button', { name: /save/i }))

    expect(save).toHaveBeenCalledWith(
      expect.objectContaining({
        credentials: { api_key: { source: 'database', value: 'sg-newkey' } }
      })
    )
  })

  it('renders the OAuth connect control instead of an input for the oauth field', () => {
    ;(useOAuthAuthorize as Mock).mockReturnValue({ mutateAsync: vi.fn(), isPending: false })

    renderWithQueryClient(
      <ConnectionEditor connection={gmailConnection} provider={gmailMeta} onSaved={() => {}} />
    )

    expect(screen.getByRole('button', { name: 'Connect' })).toBeInTheDocument()
    expect(screen.queryByLabelText('Google account')).not.toBeInTheDocument()
  })

  it('shows the backend-provided OAuth redirect URI as copyable text', () => {
    ;(useOAuthAuthorize as Mock).mockReturnValue({ mutateAsync: vi.fn(), isPending: false })
    ;(useSaveConnection as Mock).mockReturnValue({ mutateAsync: vi.fn(), isPending: false })
    const redirectUrl = 'https://site.test/bit-smtp/oauth/callback'
    const oauthProvider = {
      ...gmailMeta,
      oauth_redirect_url: redirectUrl,
      fields: gmailMeta.fields.filter(field => field.type !== 'oauth')
    } as ProviderMeta

    renderWithQueryClient(
      <ConnectionEditor connection={gmailConnection} provider={oauthProvider} onSaved={() => {}} />
    )

    expect(screen.getByText('Redirect URI')).toBeInTheDocument()
    expect(screen.getByText(redirectUrl)).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Copy' })).toBeInTheDocument()
  })

  it('does not offer delivery webhooks for Gmail', () => {
    ;(useOAuthAuthorize as Mock).mockReturnValue({ mutateAsync: vi.fn(), isPending: false })
    ;(useSaveConnection as Mock).mockReturnValue({ mutateAsync: vi.fn(), isPending: false })

    renderWithQueryClient(
      <ConnectionEditor connection={gmailConnection} provider={gmailMeta} onSaved={() => {}} />
    )

    expect(screen.queryByLabelText('Enable delivery webhook')).not.toBeInTheDocument()
    expect(screen.queryByText(/Settings → Webhooks/)).not.toBeInTheDocument()
  })

  it('shows Reconnect and a Connected tag when a refresh_token credential is present', () => {
    ;(useOAuthAuthorize as Mock).mockReturnValue({ mutateAsync: vi.fn(), isPending: false })
    const connectedGmail: Connection = {
      ...gmailConnection,
      credentials: {
        ...gmailConnection.credentials,
        refresh_token: { source: 'database', value: '********' }
      }
    }

    renderWithQueryClient(
      <ConnectionEditor connection={connectedGmail} provider={gmailMeta} onSaved={() => {}} />
    )

    expect(screen.getByRole('button', { name: 'Reconnect' })).toBeInTheDocument()
    expect(screen.getByText('Connected')).toBeInTheDocument()
  })

  it('shows a provider header with the provider logo and label', () => {
    ;(useSaveConnection as Mock).mockReturnValue({ mutateAsync: vi.fn(), isPending: false })

    render(<ConnectionEditor connection={connection} provider={otherSmtpMeta} onSaved={() => {}} />)

    expect(screen.getByAltText('Other SMTP')).toBeInTheDocument()
    expect(screen.getByText('Other SMTP')).toBeInTheDocument()
  })

  it('excludes the oauth field from the saved settings payload', async () => {
    ;(useOAuthAuthorize as Mock).mockReturnValue({ mutateAsync: vi.fn(), isPending: false })
    const save = vi.fn().mockResolvedValue({})
    ;(useSaveConnection as Mock).mockReturnValue({ mutateAsync: save, isPending: false })

    renderWithQueryClient(
      <ConnectionEditor connection={gmailConnection} provider={gmailMeta} onSaved={() => {}} />
    )

    await userEvent.click(screen.getByRole('button', { name: /save/i }))

    expect(save).toHaveBeenCalled()
    const [payload] = save.mock.calls[0] as [Connection]
    expect(payload.settings).not.toHaveProperty('oauth')
  })

  it('saves PHP Sendmail without rendering an empty provider settings section', async () => {
    const save = vi.fn().mockResolvedValue({})
    ;(useSaveConnection as Mock).mockReturnValue({ mutateAsync: save, isPending: false })

    render(
      <ConnectionEditor
        connection={phpSendmailConnection}
        provider={phpSendmailMeta}
        onSaved={() => {}}
      />
    )

    expect(screen.queryByText('Credentials & settings')).not.toBeInTheDocument()
    await userEvent.click(screen.getByRole('button', { name: /save/i }))

    expect(save).toHaveBeenCalledWith(
      expect.objectContaining({
        provider: 'php_sendmail',
        kind: 'local',
        settings: {},
        credentials: {}
      })
    )
  })
})
