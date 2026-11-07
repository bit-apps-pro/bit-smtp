import { renderHook } from '@testing-library/react'
import { afterEach, describe, expect, it, vi } from 'vitest'
import usePopupMatchSelectWidth from './usePopupMatchSelectWidth'

/** Stubs matchMedia so antd's breakpoint hook sees a viewport of the given width. */
function stubViewport(width: number) {
  vi.stubGlobal('matchMedia', (query: string) => ({
    matches: width >= Number(/min-width:\s*(\d+)px/.exec(query)?.[1] ?? 0),
    media: query,
    addEventListener: vi.fn(),
    removeEventListener: vi.fn(),
    addListener: vi.fn(),
    removeListener: vi.fn()
  }))
}

describe('usePopupMatchSelectWidth', () => {
  afterEach(() => vi.unstubAllGlobals())

  it('matches the input width on a phone so the popup stays on screen', () => {
    stubViewport(400)

    expect(renderHook(() => usePopupMatchSelectWidth()).result.current).toBe(true)
  })

  it('lets the popup grow past the input from the sm breakpoint up', () => {
    stubViewport(1600)

    expect(renderHook(() => usePopupMatchSelectWidth()).result.current).toBe(false)
  })
})
