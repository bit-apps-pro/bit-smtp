import { useEffect, useState } from 'react'
import { useLocation, useNavigate } from 'react-router-dom'

const ROUTE_HASH_PREFIX = '#/'

/** In-app route an anchor opens without leaving this document (bare `#/…` or a full same-page URL, like WP sidebar links), or null. */
function sameDocumentRoute(anchor: HTMLAnchorElement): string | null {
  const url = new URL(anchor.href, window.location.href)
  const isSameDocument =
    url.origin === window.location.origin &&
    url.pathname === window.location.pathname &&
    url.search === window.location.search

  return isSameDocument && url.hash.startsWith(ROUTE_HASH_PREFIX) ? url.hash.slice(1) : null
}

/** In-app route a plain left-click is about to open, or null for anything the guard should let through. */
function interceptedPath(event: MouseEvent, currentPath: string): string | null {
  if (event.defaultPrevented || event.button !== 0) return null
  if (event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return null

  const target = event.target instanceof Element ? event.target : null
  const anchor = target?.closest('a[href]')
  if (!(anchor instanceof HTMLAnchorElement) || anchor.target === '_blank') return null

  const path = sameDocumentRoute(anchor)
  return path === currentPath ? null : path
}

/**
 * While `isDirty`, warns before the page unloads and holds in-app link navigation for confirmation.
 * The app uses a HashRouter (no data-router `useBlocker`), so same-document route links are intercepted at the document.
 */
export default function useUnsavedChangesGuard(isDirty: boolean) {
  const navigate = useNavigate()
  const { pathname } = useLocation()
  const [pendingPath, setPendingPath] = useState<string | null>(null)

  useEffect(() => {
    if (!isDirty) return undefined

    /** Asks the browser to confirm leaving or reloading the page. */
    const warnBeforeUnload = (event: BeforeUnloadEvent) => event.preventDefault()

    /** Holds an in-app link click until the user confirms leaving. */
    const holdInAppNavigation = (event: MouseEvent) => {
      const path = interceptedPath(event, pathname)
      if (path === null) return
      event.preventDefault()
      event.stopPropagation()
      setPendingPath(path)
    }

    window.addEventListener('beforeunload', warnBeforeUnload)
    document.addEventListener('click', holdInAppNavigation, true)
    return () => {
      window.removeEventListener('beforeunload', warnBeforeUnload)
      document.removeEventListener('click', holdInAppNavigation, true)
    }
  }, [isDirty, pathname])

  /** Abandons the held navigation and stays on the page. */
  const stay = () => setPendingPath(null)

  /** Continues the held navigation, discarding unsaved changes. */
  const leave = () => {
    if (pendingPath === null) return
    setPendingPath(null)
    navigate(pendingPath)
  }

  return { isLeavePending: pendingPath !== null, stay, leave }
}
