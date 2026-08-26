import { type ReactNode } from 'react'
import notify from '@components/Toaster/Toaster'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { act, render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { type Mock, beforeEach, describe, expect, it, vi } from 'vitest'
import OAuthConnectButton from './OAuthConnectButton'
import { MAIL_SETTINGS_QUERY_KEY } from './data/useMailSettings'
import useOAuthAuthorize from './data/useOAuthAuthorize'
import useOAuthDisconnect from './data/useOAuthDisconnect'

vi.mock('./data/useOAuthAuthorize', () => ({ default: vi.fn() }))
vi.mock('./data/useOAuthDisconnect', () => ({ default: vi.fn() }))
vi.mock('@components/Toaster/Toaster', () => ({
  default: { success: vi.fn(), error: vi.fn(), warning: vi.fn(), info: vi.fn() }
}))

function renderWithClient(ui: React.ReactElement) {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  const invalidateQueries = vi.spyOn(client, 'invalidateQueries')
  function wrapper({ children }: { children: ReactNode }) {
    return <QueryClientProvider client={client}>{children}</QueryClientProvider>
  }
  const view = render(ui, { wrapper })
  return { invalidateQueries, ...view }
}

function postOAuthMessage(data: Record<string, unknown>, origin = window.location.origin) {
  act(() => {
    window.dispatchEvent(new MessageEvent('message', { origin, data }))
  })
}

describe('OAuthConnectButton', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    ;(useOAuthAuthorize as Mock).mockReturnValue({ mutateAsync: vi.fn(), isPending: false })
    ;(useOAuthDisconnect as Mock).mockReturnValue({ mutateAsync: vi.fn(), isPending: false })
  })

  it('is always enabled, and creates a draft connection before authorizing when unsaved', async () => {
    const mutateAsync = vi.fn().mockResolvedValue(undefined)
    ;(useOAuthAuthorize as Mock).mockReturnValue({ mutateAsync, isPending: false })
    const persistConnection = vi.fn().mockResolvedValue('conn_new_1')

    renderWithClient(
      <OAuthConnectButton
        connectionId=""
        provider="gmail"
        connected={false}
        persistConnection={persistConnection}
      />
    )

    const button = screen.getByRole('button', { name: 'Connect' })
    expect(button).toBeEnabled()

    await userEvent.click(button)

    expect(persistConnection).toHaveBeenCalled()
    expect(mutateAsync).toHaveBeenCalledWith({ connectionId: 'conn_new_1', provider: 'gmail' })
  })

  it('re-saves the current form values before authorizing an existing connection', async () => {
    // Regression (#7): an existing connection must persist just-typed client_id/secret before the
    // consent flow reads them; the button authorizes against the id persistConnection returns.
    const mutateAsync = vi.fn().mockResolvedValue(undefined)
    ;(useOAuthAuthorize as Mock).mockReturnValue({ mutateAsync, isPending: false })
    const persistConnection = vi.fn().mockResolvedValue('conn_1')

    renderWithClient(
      <OAuthConnectButton
        connectionId="conn_1"
        provider="gmail"
        connected
        persistConnection={persistConnection}
      />
    )

    await userEvent.click(screen.getByRole('button', { name: 'Reconnect' }))

    expect(persistConnection).toHaveBeenCalledTimes(1)
    expect(mutateAsync).toHaveBeenCalledWith({ connectionId: 'conn_1', provider: 'gmail' })
  })

  it('shows an error and never opens the popup when creating the draft connection fails', async () => {
    const mutateAsync = vi.fn()
    ;(useOAuthAuthorize as Mock).mockReturnValue({ mutateAsync, isPending: false })
    // Mirrors ConnectionEditor's real persistConnection: it notifies the user itself before
    // resolving to '', so the button only needs to bail out on an empty id.
    const persistConnection = vi.fn().mockImplementation(async () => {
      notify.error('Failed to prepare this connection for OAuth. Please try again.')
      return ''
    })
    const openSpy = vi.spyOn(window, 'open').mockReturnValue(null)

    renderWithClient(
      <OAuthConnectButton
        connectionId=""
        provider="gmail"
        connected={false}
        persistConnection={persistConnection}
      />
    )

    await userEvent.click(screen.getByRole('button', { name: 'Connect' }))

    expect(notify.error).toHaveBeenCalled()
    expect(mutateAsync).not.toHaveBeenCalled()
    expect(openSpy).not.toHaveBeenCalled()
  })

  it('enables Connect once a connection id exists', () => {
    renderWithClient(
      <OAuthConnectButton
        connectionId="conn_1"
        provider="gmail"
        connected={false}
        persistConnection={vi.fn()}
      />
    )

    expect(screen.getByRole('button', { name: 'Connect' })).toBeEnabled()
    expect(screen.queryByText('Connected')).not.toBeInTheDocument()
  })

  it('shows a Connected tag and offers Reconnect when already connected', () => {
    renderWithClient(
      <OAuthConnectButton connectionId="conn_1" provider="gmail" connected persistConnection={vi.fn()} />
    )

    expect(screen.getByText('Connected')).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Reconnect' })).toBeEnabled()
  })

  it('authorizes and opens a popup on click', async () => {
    const mutateAsync = vi.fn().mockResolvedValue('https://accounts.google.com/o/oauth2/v2/auth')
    ;(useOAuthAuthorize as Mock).mockReturnValue({ mutateAsync, isPending: false })
    const openSpy = vi.spyOn(window, 'open').mockReturnValue(null)

    renderWithClient(
      <OAuthConnectButton
        connectionId="conn_1"
        provider="gmail"
        connected={false}
        persistConnection={vi.fn().mockResolvedValue('conn_1')}
      />
    )

    await userEvent.click(screen.getByRole('button', { name: 'Connect' }))

    expect(mutateAsync).toHaveBeenCalledWith({ connectionId: 'conn_1', provider: 'gmail' })
    expect(openSpy).toHaveBeenCalledWith(
      'https://accounts.google.com/o/oauth2/v2/auth',
      'bitsmtp_oauth',
      'width=600,height=700'
    )
  })

  it('invalidates mail-settings when a matching oauth postMessage arrives', () => {
    const { invalidateQueries } = renderWithClient(
      <OAuthConnectButton
        connectionId="conn_1"
        provider="microsoft365"
        connected={false}
        persistConnection={vi.fn()}
      />
    )

    postOAuthMessage({
      type: 'bit-smtp-oauth',
      status: 'success',
      connectionId: 'conn_1',
      provider: 'microsoft365'
    })

    expect(invalidateQueries).toHaveBeenCalledWith({ queryKey: MAIL_SETTINGS_QUERY_KEY })
    expect(notify.success).toHaveBeenCalledWith('OAuth account connected')
  })

  it('shows a provider-neutral error for a failed Microsoft OAuth callback', () => {
    renderWithClient(
      <OAuthConnectButton
        connectionId="conn_1"
        provider="microsoft365"
        connected={false}
        persistConnection={vi.fn()}
      />
    )

    postOAuthMessage({
      type: 'bit-smtp-oauth',
      status: 'error',
      connectionId: 'conn_1',
      provider: 'microsoft365'
    })

    expect(notify.error).toHaveBeenCalledWith('Failed to connect OAuth account')
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

    postOAuthMessage({
      type: 'bit-smtp-oauth',
      status: 'success',
      connectionId: 'conn_other',
      provider: 'gmail'
    })

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
      { type: 'bit-smtp-oauth', status: 'success', connectionId: 'conn_1', provider: 'gmail' },
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

    // Open the confirm, then click its danger OK (both trigger and OK are named "Disconnect").
    await userEvent.click(screen.getByRole('button', { name: 'Disconnect' }))
    const buttons = await screen.findAllByRole('button', { name: 'Disconnect' })
    await userEvent.click(buttons[buttons.length - 1])

    expect(disconnect).toHaveBeenCalledWith('conn_1')
    expect(notify.success).toHaveBeenCalledWith('OAuth account disconnected')
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
