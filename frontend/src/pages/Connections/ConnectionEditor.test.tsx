import { type ReactNode } from 'react'
import notify from '@components/Toaster/Toaster'
import { type Connection, type ProviderMeta } from '@pages/Connections/types'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { type Mock, beforeEach, describe, expect, it, vi } from 'vitest'
import ConnectionEditor from './ConnectionEditor'
import useOAuthAuthorize from './data/useOAuthAuthorize'
import useSaveConnection from './data/useSaveConnection'
import useTestConnection from './data/useTestConnection'

vi.mock('./data/useSaveConnection', () => ({ default: vi.fn() }))
vi.mock('./data/useTestConnection', () => ({ default: vi.fn() }))
vi.mock('./data/useOAuthAuthorize', () => ({ default: vi.fn() }))
vi.mock('@components/Toaster/Toaster', () => ({
  default: { success: vi.fn(), error: vi.fn(), warning: vi.fn(), info: vi.fn() }
}))

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

  it('renders the test result above the sticky action bar, not nested inside it (#32)', async () => {
    ;(useSaveConnection as Mock).mockReturnValue({ mutateAsync: vi.fn(), isPending: false })
    const result = { ok: true, debug: ['Connected'], delivery: null }
    const mutate = vi.fn((_payload, opts) => opts.onSuccess(result))
    ;(useTestConnection as Mock).mockReturnValue({ mutate, isPending: false, data: undefined })

    render(<ConnectionEditor connection={connection} provider={otherSmtpMeta} onSaved={() => {}} />)

    await userEvent.click(screen.getByRole('button', { name: /test connection/i }))

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

  it('shows linked guidance for the OAuth connect field', async () => {
    ;(useOAuthAuthorize as Mock).mockReturnValue({ mutateAsync: vi.fn(), isPending: false })
    const provider = {
      ...gmailMeta,
      fields: gmailMeta.fields.map(field =>
        field.type === 'oauth'
          ? {
              ...field,
              help: {
                text: 'Connect the mailbox that will send email.',
                url: 'https://account.microsoft.com/',
                linkLabel: 'Open Microsoft account'
              }
            }
          : field
      )
    } satisfies ProviderMeta

    renderWithQueryClient(
      <ConnectionEditor connection={gmailConnection} provider={provider} onSaved={() => {}} />
    )
    await userEvent.hover(screen.getByRole('img', { name: 'question-circle' }))

    expect(await screen.findByText('Connect the mailbox that will send email.')).toBeInTheDocument()
    expect(screen.getByRole('link', { name: 'Open Microsoft account' })).toHaveAttribute(
      'href',
      'https://account.microsoft.com/'
    )
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
    expect(
      screen.getByText("Register this exact URI as a Web redirect URI in your provider's OAuth app.")
    ).toBeInTheDocument()
    expect(screen.queryByText('HTTPS redirect URI required')).not.toBeInTheDocument()
  })

  it('warns when an OAuth redirect URI uses HTTP outside localhost', () => {
    ;(useOAuthAuthorize as Mock).mockReturnValue({ mutateAsync: vi.fn(), isPending: false })
    ;(useSaveConnection as Mock).mockReturnValue({ mutateAsync: vi.fn(), isPending: false })
    const oauthProvider = {
      ...gmailMeta,
      oauth_redirect_url: 'http://wp-dev.io/bit-smtp/oauth/callback'
    } as ProviderMeta

    renderWithQueryClient(
      <ConnectionEditor connection={gmailConnection} provider={oauthProvider} onSaved={() => {}} />
    )

    expect(screen.getByText('HTTPS redirect URI required')).toBeInTheDocument()
    expect(
      screen.getByText(/OAuth providers reject non-HTTPS redirect URIs unless the host is localhost/)
    ).toBeInTheDocument()
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

  describe('webhook health indicator (#35)', () => {
    beforeEach(() => {
      ;(useSaveConnection as Mock).mockReturnValue({ mutateAsync: vi.fn(), isPending: false })
    })

    it('shows an active/verified message with the last event time when registered and verified', () => {
      const conn: Connection = {
        ...sendGridConnection,
        settings: {
          ...sendGridConnection.settings,
          webhook_verified: true,
          webhook_last_event_at: '2026-08-15 10:00:00'
        },
        webhook_provisioning: { status: 'registered', reason: null, updated_at: 1755250800 }
      }

      render(<ConnectionEditor connection={conn} provider={sendGridMeta} onSaved={() => {}} />)

      expect(screen.getByText(/verified/i)).toBeInTheDocument()
      expect(screen.getByText(/2026-08-15 10:00:00/)).toBeInTheDocument()
    })

    it('shows an awaiting-first-event message when registered but not yet verified', () => {
      const conn: Connection = {
        ...sendGridConnection,
        webhook_provisioning: { status: 'registered', reason: null, updated_at: 1755250800 }
      }

      render(<ConnectionEditor connection={conn} provider={sendGridMeta} onSaved={() => {}} />)

      expect(screen.getByText(/awaiting first event/i)).toBeInTheDocument()
      expect(screen.queryByText(/webhook active/i)).not.toBeInTheDocument()
    })

    it('renders a warning alert with the redacted failure reason and a re-check hint', () => {
      const conn: Connection = {
        ...sendGridConnection,
        webhook_provisioning: { status: 'failed', reason: 'Invalid API key', updated_at: 1755250800 }
      }

      render(<ConnectionEditor connection={conn} provider={sendGridMeta} onSaved={() => {}} />)

      const alert = screen.getByRole('alert')
      expect(alert).toHaveTextContent('Invalid API key')
      expect(alert).toHaveTextContent(/re-check|re-save/i)
    })

    it('renders an info notice naming the provider for unsupported auto-provisioning', () => {
      const conn: Connection = {
        ...sendGridConnection,
        webhook_provisioning: { status: 'unsupported', reason: null, updated_at: null }
      }

      render(<ConnectionEditor connection={conn} provider={sendGridMeta} onSaved={() => {}} />)

      const alert = screen.getByRole('alert')
      expect(alert).toHaveClass('ant-alert-info')
      expect(within(alert).getByText(/SendGrid/)).toBeInTheDocument()
      expect(alert).toHaveTextContent(/manually/i)
    })

    it('shows the public-HTTPS notice mentioning Accepted-only status when unavailable', () => {
      const conn: Connection = {
        ...sendGridConnection,
        webhook_provisioning: {
          status: 'unavailable',
          reason: 'Site is not publicly reachable',
          updated_at: null
        }
      }

      render(<ConnectionEditor connection={conn} provider={sendGridMeta} onSaved={() => {}} />)

      expect(screen.getByText(/HTTPS/)).toBeInTheDocument()
      expect(screen.getByText(/Accepted/)).toBeInTheDocument()
    })

    it('falls back to the plain verified/waiting text when webhook_provisioning is absent (pre-#35 connections)', () => {
      render(
        <ConnectionEditor connection={sendGridConnection} provider={sendGridMeta} onSaved={() => {}} />
      )

      expect(screen.getByText(/waiting for first event/i)).toBeInTheDocument()
    })
  })

  it('posts a draft connection through the existing save endpoint when Connect is clicked on an unsaved connection', async () => {
    const save = vi.fn().mockResolvedValue({
      status: 'success',
      code: 'SUCCESS',
      message: 'Connection saved',
      data: { id: 'conn_new_123' }
    })
    ;(useSaveConnection as Mock).mockReturnValue({ mutateAsync: save, isPending: false })
    const authorize = vi.fn().mockResolvedValue(undefined)
    ;(useOAuthAuthorize as Mock).mockReturnValue({ mutateAsync: authorize, isPending: false })

    renderWithQueryClient(
      <ConnectionEditor
        connection={{ ...gmailConnection, id: '' }}
        provider={gmailMeta}
        onSaved={() => {}}
      />
    )

    await userEvent.click(screen.getByRole('button', { name: 'Connect' }))

    expect(save).toHaveBeenCalledWith(expect.objectContaining({ id: '', provider: 'gmail' }))
    expect(authorize).toHaveBeenCalledWith({ connectionId: 'conn_new_123', provider: 'gmail' })
  })

  it('re-saves the current form values before authorizing an already-saved connection (#7)', async () => {
    // Without the fix Connect skipped the save for an existing id and authorized against the stored
    // client_id/secret; it must now persist the just-typed values first, then authorize.
    const save = vi.fn().mockResolvedValue({
      status: 'success',
      code: 'SUCCESS',
      message: 'Connection saved',
      data: { id: 'conn_gmail' }
    })
    ;(useSaveConnection as Mock).mockReturnValue({ mutateAsync: save, isPending: false })
    const authorize = vi.fn().mockResolvedValue(undefined)
    ;(useOAuthAuthorize as Mock).mockReturnValue({ mutateAsync: authorize, isPending: false })

    renderWithQueryClient(
      <ConnectionEditor connection={gmailConnection} provider={gmailMeta} onSaved={() => {}} />
    )

    const clientIdInput = screen.getByLabelText('Client ID')
    await userEvent.clear(clientIdInput)
    await userEvent.type(clientIdInput, 'new-client.apps.googleusercontent.com')

    await userEvent.click(screen.getByRole('button', { name: 'Connect' }))

    expect(save).toHaveBeenCalledWith(
      expect.objectContaining({
        id: 'conn_gmail',
        settings: expect.objectContaining({ client_id: 'new-client.apps.googleusercontent.com' })
      })
    )
    expect(authorize).toHaveBeenCalledWith({ connectionId: 'conn_gmail', provider: 'gmail' })
  })

  it('aborts authorize and notifies when the pre-authorize save fails for an existing connection', async () => {
    // A non-success save body must NOT authorize: the request helper resolves (not rejects) on a
    // {status:'error'} body, so without gating on success the button would authorize against the
    // stale stored client_id/secret using the still-real connection id.
    const save = vi.fn().mockResolvedValue({
      status: 'error',
      code: 'ERROR',
      message: 'Failed to save connection',
      data: {}
    })
    ;(useSaveConnection as Mock).mockReturnValue({ mutateAsync: save, isPending: false })
    const authorize = vi.fn().mockResolvedValue(undefined)
    ;(useOAuthAuthorize as Mock).mockReturnValue({ mutateAsync: authorize, isPending: false })

    renderWithQueryClient(
      <ConnectionEditor connection={gmailConnection} provider={gmailMeta} onSaved={() => {}} />
    )

    await userEvent.click(screen.getByRole('button', { name: 'Connect' }))

    expect(save).toHaveBeenCalled()
    expect(authorize).not.toHaveBeenCalled()
    expect(notify.error).toHaveBeenCalled()
  })

  it('clicking Save after Connect updates the same draft record instead of creating a duplicate', async () => {
    const save = vi.fn().mockResolvedValue({
      status: 'success',
      code: 'SUCCESS',
      message: 'Connection saved',
      data: { id: 'conn_new_123' }
    })
    ;(useSaveConnection as Mock).mockReturnValue({ mutateAsync: save, isPending: false })
    ;(useOAuthAuthorize as Mock).mockReturnValue({
      mutateAsync: vi.fn().mockResolvedValue(undefined),
      isPending: false
    })

    renderWithQueryClient(
      <ConnectionEditor
        connection={{ ...gmailConnection, id: '' }}
        provider={gmailMeta}
        onSaved={() => {}}
      />
    )

    await userEvent.click(screen.getByRole('button', { name: 'Connect' }))
    await userEvent.click(screen.getByRole('button', { name: /save/i }))

    expect(save).toHaveBeenCalledTimes(2)
    expect(save.mock.calls[1][0]).toEqual(expect.objectContaining({ id: 'conn_new_123' }))
  })
})
