import { MemoryRouter, Route, Routes, useLocation, useNavigationType } from 'react-router-dom'
import { StyleProvider } from '@ant-design/cssinjs'
import notify from '@components/Toaster/Toaster'
import ThemeProvider from '@config/themes/theme.provider'
import { type Connection, type MailSettings, type ProviderMeta } from '@pages/Connections/types'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { act, render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { type Mock, afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import ConnectionDetailPage from './ConnectionDetailPage'
import useDeleteConnection from './data/useDeleteConnection'
import useMailSettings from './data/useMailSettings'
import useOAuthAuthorize from './data/useOAuthAuthorize'
import useProviders from './data/useProviders'
import useSaveConnection from './data/useSaveConnection'

vi.mock('./data/useMailSettings', () => ({
  default: vi.fn(),
  MAIL_SETTINGS_QUERY_KEY: ['mail-settings']
}))
vi.mock('./data/useProviders', () => ({ default: vi.fn() }))
vi.mock('./data/useSaveConnection', () => ({ default: vi.fn() }))
vi.mock('./data/useOAuthAuthorize', () => ({ default: vi.fn() }))
vi.mock('./data/useDeleteConnection', () => ({ default: vi.fn() }))
vi.mock('@components/Toaster/Toaster', () => ({
  default: { success: vi.fn(), error: vi.fn(), warning: vi.fn(), info: vi.fn() }
}))

const CONSENT_URL = 'https://accounts.google.com/o/oauth2/v2/auth?client_id=abc'
const NEW_GMAIL_PATH = '/connection/new?provider=gmail'
const MINTED_ID = 'conn_new_1'

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

const gmailMeta: ProviderMeta = {
  key: 'gmail',
  label: 'Gmail',
  kind: 'api',
  fields: [
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

/** A saved SMTP connection with the given id, name and host. */
function smtpConnection(id: string, name: string, host: string): Connection {
  return {
    id,
    provider: 'other_smtp',
    kind: 'smtp',
    name,
    enabled: true,
    fromEmail: 'a@b.c',
    fromName: 'A',
    replyToEmail: '',
    settings: { host },
    credentials: {}
  }
}

const settings: MailSettings = {
  schema_version: 2,
  default_connection_id: 'conn_1',
  fallback_connection_ids: [],
  connections: [
    smtpConnection('conn_1', 'Primary SMTP', 'smtp.one'),
    smtpConnection('conn_2', 'Backup SMTP', 'smtp.two')
  ],
  features: {}
}

/** Shows the current path and how the router got there, so tests can assert on URL swaps. */
function LocationProbe() {
  const { pathname } = useLocation()
  return <output aria-label="Current route">{`${useNavigationType()} ${pathname}`}</output>
}

/** Renders the app's connection routes at the given URL. */
function renderAt(path: string) {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  return render(
    <QueryClientProvider client={client}>
      <ThemeProvider>
        <StyleProvider hashPriority="high">
          <MemoryRouter initialEntries={[path]}>
            <LocationProbe />
            <Routes>
              <Route path="/" element={<p>Connections list</p>} />
              <Route path="/connection/new" element={<ConnectionDetailPage />} />
              <Route path="/connection/:id" element={<ConnectionDetailPage />} />
            </Routes>
          </MemoryRouter>
        </StyleProvider>
      </ThemeProvider>
    </QueryClientProvider>
  )
}

/** Makes the save endpoint mint the given id for a new connection. */
function mockSaveMinting(id: string) {
  const save = vi
    .fn()
    .mockResolvedValue({ status: 'success', message: 'Connection saved', data: { id } })
  ;(useSaveConnection as Mock).mockReturnValue({ mutateAsync: save, isPending: false })
  return save
}

/** Stubs window.open with a controllable sign-in popup. */
function stubPopup() {
  const popup = { closed: false, close: vi.fn(), location: { href: '' } }
  vi.spyOn(window, 'open').mockReturnValue(popup as unknown as Window)
  return popup
}

/** Fills the only required blank field and starts the save-and-sign-in flow on a new Gmail connection. */
async function startNewGmailSignIn() {
  renderAt(NEW_GMAIL_PATH)
  await userEvent.type(screen.getByLabelText('From email'), 'owner@example.com')
  await userEvent.click(screen.getByRole('button', { name: 'Save and sign in with Google' }))
}

describe('ConnectionDetailPage', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    ;(useMailSettings as Mock).mockReturnValue({ data: settings, isPending: false })
    ;(useProviders as Mock).mockReturnValue({ data: [otherSmtpMeta, gmailMeta], isPending: false })
    ;(useOAuthAuthorize as Mock).mockReturnValue({
      mutateAsync: vi.fn().mockResolvedValue(CONSENT_URL),
      isPending: false
    })
    ;(useDeleteConnection as Mock).mockReturnValue({
      mutateAsync: vi.fn().mockResolvedValue(undefined),
      isPending: false
    })
    mockSaveMinting(MINTED_ID)
  })

  afterEach(() => vi.restoreAllMocks())

  it('renders the editor pre-filled with the connection matching the :id param', () => {
    renderAt('/connection/conn_2')

    expect(screen.getByLabelText('Connection name')).toHaveValue('Backup SMTP')
    expect(screen.getByLabelText('SMTP Host')).toHaveValue('smtp.two')
  })

  it('shows a loading spinner while settings are pending', () => {
    ;(useMailSettings as Mock).mockReturnValue({ data: undefined, isPending: true })

    const { container } = renderAt('/connection/conn_2')

    expect(container.querySelector('.ant-spin')).toBeInTheDocument()
  })

  it('shows a not-found message with a back link for an unknown id', () => {
    renderAt('/connection/does-not-exist')

    expect(screen.getByText('Connection not found')).toBeInTheDocument()
    expect(screen.getByRole('link', { name: 'Back to connections' })).toBeInTheDocument()
  })

  it('renders a new editor for the requested provider, named after it', () => {
    renderAt('/connection/new?provider=other_smtp')

    expect(screen.getByLabelText('Connection name')).toHaveValue('Other SMTP')
    expect(screen.getByLabelText('SMTP Host')).toBeInTheDocument()
  })

  it('shows a loading spinner while providers are pending', () => {
    ;(useProviders as Mock).mockReturnValue({ data: undefined, isPending: true })

    const { container } = renderAt('/connection/new?provider=other_smtp')

    expect(container.querySelector('.ant-spin')).toBeInTheDocument()
  })

  it.each(['/connection/new', '/connection/new?provider=does-not-exist'])(
    'opens the provider picker when %s names no provider it knows',
    path => {
      renderAt(path)

      expect(screen.getByRole('dialog', { name: 'Choose a provider' })).toBeInTheDocument()
    }
  )

  it('continues to the chosen provider’s form when one is picked', async () => {
    renderAt('/connection/new')

    await userEvent.click(screen.getByRole('button', { name: /Other SMTP/ }))

    expect(await screen.findByLabelText('Connection name')).toHaveValue('Other SMTP')
    expect(screen.getByLabelText('Current route')).toHaveTextContent('REPLACE /connection/new')
  })

  it('returns to the connections list when the picker is closed without a choice', async () => {
    renderAt('/connection/new')

    await userEvent.click(screen.getByRole('button', { name: 'Close' }))

    expect(await screen.findByText('Connections list')).toBeInTheDocument()
  })

  describe('first save of a new OAuth connection', () => {
    it('replaces the URL with the saved connection’s edit route, so a reload reopens it', async () => {
      mockSaveMinting(MINTED_ID)
      const popup = stubPopup()

      await startNewGmailSignIn()

      await waitFor(() =>
        expect(screen.getByRole('status', { name: 'Current route' })).toHaveTextContent(
          `REPLACE /connection/${MINTED_ID}`
        )
      )
      await waitFor(() => expect(popup.location.href).toBe(CONSENT_URL))
    })

    it('keeps the same editor mounted, so the sign-in finishes and shows the connected account', async () => {
      mockSaveMinting(MINTED_ID)
      stubPopup()

      await startNewGmailSignIn()
      await waitFor(() =>
        expect(screen.getByRole('status', { name: 'Current route' })).toHaveTextContent(MINTED_ID)
      )
      ;(useMailSettings as Mock).mockReturnValue({
        data: {
          ...settings,
          connections: [
            {
              ...smtpConnection(MINTED_ID, 'Gmail', ''),
              provider: 'gmail',
              settings: { oauth_account: 'owner@example.com' },
              credentials: { refresh_token: { source: 'database', value: '********' } }
            }
          ]
        },
        isPending: false
      })
      act(() => {
        window.dispatchEvent(
          new MessageEvent('message', {
            origin: window.location.origin,
            data: { type: 'bit-smtp-oauth', status: 'success', connectionId: MINTED_ID }
          })
        )
      })

      expect(notify.success).toHaveBeenCalledWith('Account connected')
      expect(screen.getByText('Connected as owner@example.com')).toBeInTheDocument()
      expect(screen.getByLabelText('From email')).toHaveValue('owner@example.com')
    })

    it('keeps waiting for sign-in after the URL swap and offers to remove the connection, then returns to the list', async () => {
      mockSaveMinting(MINTED_ID)
      const deleteConnection = vi.fn().mockResolvedValue(undefined)
      ;(useDeleteConnection as Mock).mockReturnValue({ mutateAsync: deleteConnection, isPending: false })
      stubPopup()

      await startNewGmailSignIn()
      await waitFor(() =>
        expect(screen.getByRole('status', { name: 'Current route' })).toHaveTextContent(MINTED_ID)
      )

      const remove = await screen.findByRole('button', { name: 'Remove this connection' })
      expect(screen.getByRole('alert')).toHaveTextContent('Finish signing in in the Google window.')
      await userEvent.click(remove)

      expect(deleteConnection).toHaveBeenCalledWith(MINTED_ID)
      expect(await screen.findByText('Connections list')).toBeInTheDocument()
    })
  })
})
