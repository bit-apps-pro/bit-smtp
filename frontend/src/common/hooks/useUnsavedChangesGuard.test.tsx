import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, beforeEach, describe, expect, it } from 'vitest'
import useUnsavedChangesGuard from './useUnsavedChangesGuard'

/** Minimal page that reports whether the guard is holding a navigation. */
function GuardedPage({ href }: { href: string }) {
  const { isLeavePending, leave } = useUnsavedChangesGuard(true)

  return (
    <>
      <a href={href}>Leave link</a>
      {isLeavePending ? (
        <button type="button" onClick={leave}>
          Confirm leave
        </button>
      ) : null}
    </>
  )
}

/** Renders the guarded page at /connections with a /logs route to land on. */
function renderGuard(href: string) {
  return render(
    <MemoryRouter initialEntries={['/connections']}>
      <Routes>
        <Route path="/connections" element={<GuardedPage href={href} />} />
        <Route path="/logs" element={<p>Logs page</p>} />
      </Routes>
    </MemoryRouter>
  )
}

const PLUGIN_PAGE = '/wp-admin/admin.php?page=bit-smtp'

/** Stops jsdom from attempting real cross-document navigation once the guard has let a click through. */
const blockDocumentNavigation = (event: MouseEvent) => event.preventDefault()

describe('useUnsavedChangesGuard', () => {
  beforeEach(() => {
    window.history.replaceState(null, '', PLUGIN_PAGE)
    document.addEventListener('click', blockDocumentNavigation)
  })

  afterEach(() => {
    document.removeEventListener('click', blockDocumentNavigation)
  })

  it('holds a bare in-app hash link', async () => {
    renderGuard('#/logs')

    await userEvent.click(screen.getByText('Leave link'))

    expect(screen.getByText('Confirm leave')).toBeInTheDocument()
  })

  it('holds a same-document link that carries the page path and query, like the WordPress sidebar', async () => {
    renderGuard('admin.php?page=bit-smtp#/logs')

    await userEvent.click(screen.getByText('Leave link'))

    expect(screen.getByText('Confirm leave')).toBeInTheDocument()
  })

  it('holds an absolute same-document link and continues to its route on confirm', async () => {
    renderGuard(`${window.location.origin}${PLUGIN_PAGE}#/logs`)

    await userEvent.click(screen.getByText('Leave link'))
    await userEvent.click(screen.getByText('Confirm leave'))

    expect(screen.getByText('Logs page')).toBeInTheDocument()
  })

  it('lets a link to the current route through', async () => {
    renderGuard(`${PLUGIN_PAGE}#/connections`)

    await userEvent.click(screen.getByText('Leave link'))

    expect(screen.queryByText('Confirm leave')).not.toBeInTheDocument()
  })

  it.each([
    ['another admin page', '/wp-admin/options-general.php#/logs'],
    ['another plugin screen', 'admin.php?page=other-plugin#/logs'],
    ['a non-route anchor', '#main-content']
  ])('leaves %s to the unload warning', async (_label, href) => {
    renderGuard(href)

    await userEvent.click(screen.getByText('Leave link'))

    expect(screen.queryByText('Confirm leave')).not.toBeInTheDocument()
  })
})
