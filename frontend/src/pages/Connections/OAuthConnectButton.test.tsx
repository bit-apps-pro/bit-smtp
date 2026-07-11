import { type ReactNode } from 'react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { act, render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { type Mock, beforeEach, describe, expect, it, vi } from 'vitest'
import OAuthConnectButton from './OAuthConnectButton'
import { MAIL_SETTINGS_QUERY_KEY } from './data/useMailSettings'
import useOAuthAuthorize from './data/useOAuthAuthorize'

vi.mock('./data/useOAuthAuthorize', () => ({ default: vi.fn() }))

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
  })

  it('disables Connect until the connection has been saved', () => {
    renderWithClient(<OAuthConnectButton connectionId="" provider="gmail" connected={false} />)

    expect(screen.getByRole('button', { name: 'Connect' })).toBeDisabled()
  })

  it('enables Connect once a connection id exists', () => {
    renderWithClient(<OAuthConnectButton connectionId="conn_1" provider="gmail" connected={false} />)

    expect(screen.getByRole('button', { name: 'Connect' })).toBeEnabled()
    expect(screen.queryByText('Connected')).not.toBeInTheDocument()
  })

  it('shows a Connected tag and offers Reconnect when already connected', () => {
    renderWithClient(<OAuthConnectButton connectionId="conn_1" provider="gmail" connected />)

    expect(screen.getByText('Connected')).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Reconnect' })).toBeEnabled()
  })

  it('authorizes and opens a popup on click', async () => {
    const mutateAsync = vi.fn().mockResolvedValue('https://accounts.google.com/o/oauth2/v2/auth')
    ;(useOAuthAuthorize as Mock).mockReturnValue({ mutateAsync, isPending: false })
    const openSpy = vi.spyOn(window, 'open').mockReturnValue(null)

    renderWithClient(<OAuthConnectButton connectionId="conn_1" provider="gmail" connected={false} />)

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
      <OAuthConnectButton connectionId="conn_1" provider="gmail" connected={false} />
    )

    postOAuthMessage({
      type: 'bit-smtp-oauth',
      status: 'success',
      connectionId: 'conn_1',
      provider: 'gmail'
    })

    expect(invalidateQueries).toHaveBeenCalledWith({ queryKey: MAIL_SETTINGS_QUERY_KEY })
  })

  it('ignores a postMessage for a different connection', () => {
    const { invalidateQueries } = renderWithClient(
      <OAuthConnectButton connectionId="conn_1" provider="gmail" connected={false} />
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
      <OAuthConnectButton connectionId="conn_1" provider="gmail" connected={false} />
    )

    postOAuthMessage(
      { type: 'bit-smtp-oauth', status: 'success', connectionId: 'conn_1', provider: 'gmail' },
      'https://evil.example.com'
    )

    expect(invalidateQueries).not.toHaveBeenCalled()
  })

  it('removes the message listener on unmount', () => {
    const removeEventListenerSpy = vi.spyOn(window, 'removeEventListener')

    const { unmount } = renderWithClient(
      <OAuthConnectButton connectionId="conn_1" provider="gmail" connected={false} />
    )

    unmount()

    expect(removeEventListenerSpy).toHaveBeenCalledWith('message', expect.any(Function))
  })
})
