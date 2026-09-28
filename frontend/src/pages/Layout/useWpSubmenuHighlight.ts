import { useEffect } from 'react'
import { useLocation } from 'react-router-dom'

const SUBMENU_ITEMS_SELECTOR = '#toplevel_page_bit-smtp .wp-submenu li'
const CURRENT_CLASS = 'current'

/** Hash route an admin submenu link points to ("/" when it has no hash). */
function routeOf(link: HTMLAnchorElement): string {
  return link.hash.replace(/^#/, '') || '/'
}

/** True when `pathname` is `route` itself or nested under it (so /logsx never matches /logs). */
function isWithinRoute(pathname: string, route: string): boolean {
  return pathname === route || pathname.startsWith(`${route}/`)
}

/** Keep the WordPress sidebar's active Bit SMTP submenu in sync with the in-app hash route; unmatched paths (e.g. /connection/3) belong to the root "Configuration" entry. */
export default function useWpSubmenuHighlight(): void {
  const { pathname } = useLocation()

  useEffect(() => {
    const items = document.querySelectorAll<HTMLLIElement>(SUBMENU_ITEMS_SELECTOR)
    const linked = Array.from(items).flatMap(item => {
      const link = item.querySelector<HTMLAnchorElement>('a')
      return link ? [{ item, link, route: routeOf(link) }] : []
    })
    const active =
      linked.find(({ route }) => route !== '/' && isWithinRoute(pathname, route)) ??
      linked.find(({ route }) => route === '/')

    linked.forEach(({ item, link }) => {
      const isActive = item === active?.item
      item.classList.toggle(CURRENT_CLASS, isActive)
      link.classList.toggle(CURRENT_CLASS, isActive)
      if (isActive) {
        link.setAttribute('aria-current', 'page')
      } else {
        link.removeAttribute('aria-current')
      }
    })
  }, [pathname])
}
