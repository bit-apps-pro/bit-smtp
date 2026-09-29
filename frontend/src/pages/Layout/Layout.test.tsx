import { Route, Routes } from 'react-router-dom'
import request from '@common/helpers/request'
import { renderWithProviders } from '@config/test-utils'
import { screen, within } from '@testing-library/react'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import Layout from './Layout'

vi.mock('@common/helpers/request', () => ({ default: vi.fn() }))

/** Render the shell at the given hash route with a stub page. */
function renderShellAt(hash: string) {
  window.location.hash = hash
  return renderWithProviders(
    <Routes>
      <Route path="/" element={<Layout />}>
        <Route index element={<p>Connections page</p>} />
        <Route path="*" element={<p>Other page</p>} />
      </Route>
    </Routes>
  )
}

describe('Layout shell', () => {
  beforeEach(() => {
    vi.mocked(request).mockResolvedValue({
      status: 'success',
      code: 'SUCCESS',
      message: undefined,
      data: { settings: { connections: [], features: {} } } as never
    })
  })

  afterEach(() => {
    vi.mocked(request).mockReset()
    localStorage.clear()
    window.location.hash = ''
  })

  it('lists primary nav in setup order without the cross-promo page', () => {
    renderShellAt('#/')

    const nav = screen.getByRole('navigation', { name: 'Bit SMTP sections' })
    expect(
      within(nav)
        .getAllByRole('link')
        .map(link => link.textContent)
    ).toEqual(['Connections', 'Routing', 'Logs', 'Analytics', 'Test email', 'Settings'])
  })

  it('moves the cross-promo page to a footer link', () => {
    renderShellAt('#/')

    expect(screen.getByRole('contentinfo')).toContainElement(
      screen.getByRole('link', { name: 'More plugins from Bit Apps' })
    )
  })

  it('does not nest a main landmark inside the WordPress admin main', () => {
    renderShellAt('#/')

    expect(screen.queryByRole('main')).not.toBeInTheDocument()
    expect(screen.getByRole('region', { name: 'Bit SMTP' })).toHaveTextContent('Connections page')
  })

  it('shows the setup checklist only on the Connections page', async () => {
    const { unmount } = renderShellAt('#/')
    expect(await screen.findByRole('region', { name: 'Get started with Bit SMTP' })).toBeInTheDocument()
    unmount()

    renderShellAt('#/logs')
    expect(screen.getByText('Other page')).toBeInTheDocument()
    expect(screen.queryByRole('region', { name: 'Get started with Bit SMTP' })).not.toBeInTheDocument()
  })
})
