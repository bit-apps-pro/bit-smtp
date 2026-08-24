import { type CSSProperties, type KeyboardEventHandler, type MouseEventHandler } from 'react'

interface ClickableRowProps {
  role: 'button'
  tabIndex: number
  style: CSSProperties
  onClick: MouseEventHandler
  onKeyDown: KeyboardEventHandler
}

/** Enter/Space triggers activation the same way a click does, matching native button semantics. */
function isActivationKey(key: string): boolean {
  return key === 'Enter' || key === ' '
}

/** A11y props turning a non-button row into a clickable, keyboard-reachable control (click + Enter/Space). */
export default function clickableRowProps(onActivate: () => void): ClickableRowProps {
  return {
    role: 'button',
    tabIndex: 0,
    style: { cursor: 'pointer' },
    onClick: onActivate,
    onKeyDown: event => {
      if (!isActivationKey(event.key)) return
      // Space would otherwise scroll the page like it does for a native button.
      event.preventDefault()
      onActivate()
    }
  }
}
