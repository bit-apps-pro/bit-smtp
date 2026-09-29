import { type RefObject, useCallback, useEffect, useRef, useState } from 'react'

/** Sub-pixel slack so fractional scroll positions still count as "at the edge". */
const EDGE_TOLERANCE_PX = 1

export interface HorizontalOverflow<T extends HTMLElement> {
  ref: RefObject<T | null>
  /** Content is hidden off the start (left) edge. */
  overflowStart: boolean
  /** Content is hidden off the end (right) edge. */
  overflowEnd: boolean
}

/** Tracks whether a horizontally scrollable element hides content at either edge, for scroll affordances. */
export default function useHorizontalOverflow<T extends HTMLElement>(): HorizontalOverflow<T> {
  const ref = useRef<T>(null)
  const [overflow, setOverflow] = useState({ start: false, end: false })

  const measure = useCallback(() => {
    const element = ref.current
    if (!element) {
      return
    }
    const maxScroll = element.scrollWidth - element.clientWidth
    const start = element.scrollLeft > EDGE_TOLERANCE_PX
    const end = maxScroll - element.scrollLeft > EDGE_TOLERANCE_PX
    setOverflow(previous =>
      previous.start === start && previous.end === end ? previous : { start, end }
    )
  }, [])

  useEffect(() => {
    const element = ref.current
    if (!element) {
      return undefined
    }
    measure()
    element.addEventListener('scroll', measure, { passive: true })
    const observer = typeof ResizeObserver === 'undefined' ? undefined : new ResizeObserver(measure)
    observer?.observe(element)
    return () => {
      element.removeEventListener('scroll', measure)
      observer?.disconnect()
    }
  }, [measure])

  return { ref, overflowStart: overflow.start, overflowEnd: overflow.end }
}
