import { MemoryRouter } from 'react-router-dom'
import { render } from '@testing-library/react'
import { afterEach, describe, expect, it } from 'vitest'
import useWpSubmenuHighlight from './useWpSubmenuHighlight'

const SUBMENU_HTML = `
  <li id="toplevel_page_bit-smtp"><ul class="wp-submenu">
    <li class="current"><a class="current" href="admin.php?page=bit-smtp">Configuration</a></li>
    <li><a href="admin.php?page=bit-smtp#/logs">Logs</a></li>
    <li><a href="admin.php?page=bit-smtp#/settings">Settings</a></li>
  </ul></li>`

/** Mounts the hook under a router at the given in-app path. */
function Probe() {
  useWpSubmenuHighlight()
  return null
}

/** Names of submenu items currently marked active. */
function activeItems(): string[] {
  return Array.from(document.querySelectorAll('.wp-submenu li.current')).map(li => li.textContent ?? '')
}

describe('useWpSubmenuHighlight', () => {
  afterEach(() => {
    document.getElementById('toplevel_page_bit-smtp')?.remove()
  })

  it.each([
    ['/logs', 'Logs'],
    ['/logs/12', 'Logs'],
    ['/logsx', 'Configuration'],
    ['/settings', 'Settings'],
    ['/connection/3', 'Configuration'],
    ['/', 'Configuration']
  ])('marks the submenu owning %s as current', (path, expected) => {
    document.body.insertAdjacentHTML('beforeend', SUBMENU_HTML)
    render(
      <MemoryRouter initialEntries={[path]}>
        <Probe />
      </MemoryRouter>
    )

    expect(activeItems()).toEqual([expected])
    expect(document.querySelector('a[aria-current="page"]')?.textContent).toBe(expected)
  })
})
