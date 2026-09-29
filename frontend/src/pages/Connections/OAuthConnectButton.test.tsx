import { type ReactElement, type ReactNode } from 'react'
import notify from '@components/Toaster/Toaster'
import { type Connection } from '@pages/Connections/types'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { act, render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { Form, Input } from 'antd'
import { type Mock, afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import OAuthConnectButton from './OAuthConnectButton'
import useDeleteConnection from './data/useDeleteConnection'
import useMailSettings, { MAIL_SETTINGS_QUERY_KEY } from './data/useMailSettings'
import useOAuthAuthorize from './data/useOAuthAuthorize'
import useOAuthDisconnect from './data/useOAuthDisconnect'

vi.mock('./data/useOAuthAuthorize', () => ({ default: vi.fn() }))
vi.mock('./data/useOAuthDisconnect', () => ({ default: vi.fn() }))
vi.mock('./data/useDeleteConnection', () => ({ default: vi.fn() }))
vi.mock('./data/useMailSettings', () => ({
  default: vi.fn(),
  MAIL_SETTINGS_QUERY_KEY: ['mail-settings']
}))
vi.mock('@components/Toaster/Toaster', () => ({
  default: { success: vi.fn(), error: vi.fn(), warning: vi.fn(), info: vi.fn() }
}))

const CONSENT_URL = 'https://accounts.google.com/o/oauth2/v2/auth?client_id=abc'

interface FakePopup {
  closed: boolean
  close: Mock
  location: { href: string }
}

/** Renders with a QueryClientProvider and a spy on its invalidateQueries, for cache-invalidation assertions. */
function renderWithClient(ui: ReactElement) {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  const invalidateQueries = vi.spyOn(client, 'invalidateQueries')
  function wrapper({ children }: { children: ReactNode }) {
    return <QueryClientProvider client={client}>{children}</QueryClientProvider>
  }
  return { invalidateQueries, ...render(ui, { wrapper }) }
}

/** Dispatches a window "message" event shaped like the OAuth popup's callback payload. */
function postOAuthMessage(data: Record<string, unknown>, origin = window.location.origin) {
  act(() => {
    window.dispatchEvent(new MessageEvent('message', { origin, data }))
  })
}

/** Stubs window.open with a controllable popup (or null to simulate a popup blocker). */
function stubPopup(popup: FakePopup | null = { closed: false, close: vi.fn(), location: { href: '' } }) {
  const open = vi.spyOn(window, 'open').mockReturnValue(popup as unknown as Window)
  return { open, popup }
}

/** Makes the authorize mutation resolve with the URL, or reject with the error. */
function mockAuthorize(result: string | Error) {
  const authorize =
    result instanceof Error ? vi.fn().mockRejectedValue(result) : vi.fn().mockResolvedValue(result)
  ;(useOAuthAuthorize as Mock).mockReturnValue({ mutateAsync: authorize, isPending: false })
  return authorize
}

/** Makes the cached mail settings contain the given connections. */
function mockStoredConnections(connections: Partial<Connection>[]) {
  ;(useMailSettings as Mock).mockReturnValue({ data: { connections } })
}

describe('OAuthConnectButton', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    mockAuthorize(CONSENT_URL)
    ;(useOAuthDisconnect as Mock).mockReturnValue({ mutateAsync: vi.fn(), isPending: false })
    ;(useDeleteConnection as Mock).mockReturnValue({ mutateAsync: vi.fn(), isPending: false })
    ;(useMailSettings as Mock).mockReturnValue({ data: undefined })
  })

  afterEach(() => vi.mocked(window.open).mockRestore?.())

  it('says it will save a new connection first, and names the provider', () => {
    renderWithClient(
      <OAuthConnectButton
        connectionId=""
        provider="gmail"
        connected={false}
        persistConnection={vi.fn()}
      />
    )

    const button = screen.getByRole('button', { name: 'Save and sign in with Google' })
    expect(button).toHaveAccessibleDescription(/saves the connection first/)
  })

  it('names Microsoft for Microsoft 365 connections', () => {
    renderWithClient(
      <OAuthConnectButton
        connectionId="conn_1"
        provider="microsoft365"
        connected={false}
        persistConnection={vi.fn()}
      />
    )

    expect(screen.getByRole('button', { name: 'Sign in with Microsoft' })).toBeEnabled()
  })

  it('opens the popup before any await, then saves, authorizes and navigates it', async () => {
    const { open, popup } = stubPopup()
    const authorize = mockAuthorize(CONSENT_URL)
    const persistConnection = vi.fn().mockImplementation(async () => {
      expect(open).toHaveBeenCalledWith('', 'bitsmtp_oauth', 'width=600,height=700')
      return 'conn_new_1'
    })

    renderWithClient(
      <OAuthConnectButton
        connectionId=""
        provider="gmail"
        connected={false}
        persistConnection={persistConnection}
      />
    )
    await userEvent.click(screen.getByRole('button', { name: 'Save and sign in with Google' }))

    expect(persistConnection).toHaveBeenCalledTimes(1)
    expect(authorize).toHaveBeenCalledWith({ connectionId: 'conn_new_1', provider: 'gmail' })
    await waitFor(() => expect(popup?.location.href).toBe(CONSENT_URL))
  })

  it('validates the form first and never saves an incomplete one', async () => {
    const { popup } = stubPopup()
    const persistConnection = vi.fn()

    renderWithClient(
      <Form>
        <Form.Item name="fromEmail" rules={[{ required: true, message: 'Required' }]}>
          <Input />
        </Form.Item>
        <OAuthConnectButton
          connectionId="conn_1"
          provider="gmail"
          connected={false}
          persistConnection={persistConnection}
        />
      </Form>
    )
    await userEvent.click(screen.getByRole('button', { name: 'Sign in with Google' }))

    await waitFor(() => expect(popup?.close).toHaveBeenCalled())
    expect(persistConnection).not.toHaveBeenCalled()
    expect(notify.error).toHaveBeenCalledWith('Fill in the highlighted fields, then sign in again.')
  })

  it('closes the popup and stops when saving fails', async () => {
    const { popup } = stubPopup()
    const authorize = mockAuthorize(CONSENT_URL)

    renderWithClient(
      <OAuthConnectButton
        connectionId=""
        provider="gmail"
        connected={false}
        persistConnection={vi.fn().mockResolvedValue('')}
      />
    )
    await userEvent.click(screen.getByRole('button', { name: 'Save and sign in with Google' }))

    expect(popup?.close).toHaveBeenCalled()
    expect(authorize).not.toHaveBeenCalled()
  })

  it("shows the server's reason and closes the popup when the consent URL can't be built", async () => {
    const { popup } = stubPopup()
    mockAuthorize(new Error('This connection is missing its Client ID'))

    renderWithClient(
      <OAuthConnectButton
        connectionId="conn_1"
        provider="gmail"
        connected={false}
        persistConnection={vi.fn().mockResolvedValue('conn_1')}
      />
    )
    await userEvent.click(screen.getByRole('button', { name: 'Sign in with Google' }))

    expect(popup?.close).toHaveBeenCalled()
    expect(notify.error).toHaveBeenCalledWith('This connection is missing its Client ID')
  })

  it('never loads a non-https URL into the popup', async () => {
    const { popup } = stubPopup()
    mockAuthorize('http://accounts.example.test/consent')

    renderWithClient(
      <OAuthConnectButton
        connectionId="conn_1"
        provider="gmail"
        connected={false}
        persistConnection={vi.fn().mockResolvedValue('conn_1')}
      />
    )
    await userEvent.click(screen.getByRole('button', { name: 'Sign in with Google' }))

    expect(popup?.close).toHaveBeenCalled()
    expect(popup?.location.href).toBe('')
  })

  it('offers a direct link when the browser blocks the popup', async () => {
    const { open } = stubPopup(null)

    renderWithClient(
      <OAuthConnectButton
        connectionId="conn_1"
        provider="gmail"
        connected={false}
        persistConnection={vi.fn().mockResolvedValue('conn_1')}
      />
    )
    await userEvent.click(screen.getByRole('button', { name: 'Sign in with Google' }))

    expect(await screen.findByText('Your browser blocked the sign-in window.')).toBeInTheDocument()
    await userEvent.click(screen.getByRole('button', { name: 'Open sign-in page' }))
    expect(open).toHaveBeenLastCalledWith(CONSENT_URL, 'bitsmtp_oauth', 'width=600,height=700')
    expect(screen.queryByText('Your browser blocked the sign-in window.')).not.toBeInTheDocument()
  })

  it('shows which account is connected and offers to sign in again', () => {
    mockStoredConnections([
      {
        id: 'conn_1',
        settings: { oauth_account: 'owner@example.com' },
        credentials: { refresh_token: { source: 'database', value: '********' } }
      }
    ])

    renderWithClient(
      <OAuthConnectButton
        connectionId="conn_1"
        provider="gmail"
        connected={false}
        persistConnection={vi.fn()}
      />
    )

    expect(screen.getByText('Connected as owner@example.com')).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Sign in again' })).toBeEnabled()
  })

  it('falls back to the editor snapshot before the connection is in the cache', () => {
    renderWithClient(
      <OAuthConnectButton connectionId="conn_1" provider="gmail" connected persistConnection={vi.fn()} />
    )

    expect(screen.getByText('Connected')).toBeInTheDocument()
  })

  it('refreshes settings and confirms when the popup reports success', () => {
    const { invalidateQueries } = renderWithClient(
      <OAuthConnectButton
        connectionId="conn_1"
        provider="microsoft365"
        connected={false}
        persistConnection={vi.fn()}
      />
    )

    postOAuthMessage({ type: 'bit-smtp-oauth', status: 'success', connectionId: 'conn_1' })

    expect(invalidateQueries).toHaveBeenCalledWith({ queryKey: MAIL_SETTINGS_QUERY_KEY })
    expect(notify.success).toHaveBeenCalledWith('Account connected')
  })

  it('explains a failure reason in plain words', () => {
    renderWithClient(
      <OAuthConnectButton
        connectionId="conn_1"
        provider="gmail"
        connected={false}
        persistConnection={vi.fn()}
      />
    )

    postOAuthMessage({ type: 'bit-smtp-oauth', status: 'error', reason: 'access_denied' })

    expect(screen.getByRole('alert')).toHaveTextContent(
      "Google didn't allow access. Sign in again and approve every permission it asks for."
    )
    expect(screen.queryByRole('button', { name: 'Remove this connection' })).not.toBeInTheDocument()
  })

  it('offers to remove a connection it saved when sign-in then fails', async () => {
    stubPopup()
    const deleteConnection = vi.fn().mockResolvedValue(undefined)
    ;(useDeleteConnection as Mock).mockReturnValue({ mutateAsync: deleteConnection, isPending: false })
    const persistConnection = vi.fn().mockResolvedValue('conn_new_1')

    const { rerender } = renderWithClient(
      <OAuthConnectButton
        connectionId=""
        provider="gmail"
        connected={false}
        persistConnection={persistConnection}
      />
    )
    await userEvent.click(screen.getByRole('button', { name: 'Save and sign in with Google' }))
    rerender(
      <OAuthConnectButton
        connectionId="conn_new_1"
        provider="gmail"
        connected={false}
        persistConnection={persistConnection}
      />
    )
    postOAuthMessage({ type: 'bit-smtp-oauth', status: 'error', reason: 'invalid_client' })
    await userEvent.click(screen.getByRole('button', { name: 'Remove this connection' }))

    expect(deleteConnection).toHaveBeenCalledWith('conn_new_1')
    expect(notify.success).toHaveBeenCalledWith('Connection removed')
  })

  it('ignores a postMessage for a different connection', () => {
    const { invalidateQueries } = renderWithClient(
      <OAuthConnectButton
        connectionId="conn_1"
        provider="gmail"
        connected={false}
        persistConnection={vi.fn()}
      />
    )

    postOAuthMessage({ type: 'bit-smtp-oauth', status: 'success', connectionId: 'conn_other' })

    expect(invalidateQueries).not.toHaveBeenCalled()
  })

  it('ignores a postMessage from a foreign origin', () => {
    const { invalidateQueries } = renderWithClient(
      <OAuthConnectButton
        connectionId="conn_1"
        provider="gmail"
        connected={false}
        persistConnection={vi.fn()}
      />
    )

    postOAuthMessage(
      { type: 'bit-smtp-oauth', status: 'success', connectionId: 'conn_1' },
      'https://evil.example.com'
    )

    expect(invalidateQueries).not.toHaveBeenCalled()
  })

  it('offers Disconnect only when connected, and clears tokens on confirm', async () => {
    const disconnect = vi.fn().mockResolvedValue(undefined)
    ;(useOAuthDisconnect as Mock).mockReturnValue({ mutateAsync: disconnect, isPending: false })

    renderWithClient(
      <OAuthConnectButton connectionId="conn_1" provider="gmail" connected persistConnection={vi.fn()} />
    )

    await userEvent.click(screen.getByRole('button', { name: 'Disconnect' }))
    const buttons = await screen.findAllByRole('button', { name: 'Disconnect' })
    await userEvent.click(buttons[buttons.length - 1])

    expect(disconnect).toHaveBeenCalledWith('conn_1')
    expect(notify.success).toHaveBeenCalledWith('Account disconnected')
  })

  it('hides Disconnect when not connected', () => {
    renderWithClient(
      <OAuthConnectButton
        connectionId="conn_1"
        provider="gmail"
        connected={false}
        persistConnection={vi.fn()}
      />
    )

    expect(screen.queryByRole('button', { name: 'Disconnect' })).not.toBeInTheDocument()
  })

  it('removes the message listener on unmount', () => {
    const removeEventListenerSpy = vi.spyOn(window, 'removeEventListener')

    const { unmount } = renderWithClient(
      <OAuthConnectButton
        connectionId="conn_1"
        provider="gmail"
        connected={false}
        persistConnection={vi.fn()}
      />
    )
    unmount()

    expect(removeEventListenerSpy).toHaveBeenCalledWith('message', expect.any(Function))
  })
})
