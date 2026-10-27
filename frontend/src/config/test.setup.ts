// eslint-disable-next-line import/no-extraneous-dependencies
import '@testing-library/jest-dom/vitest'
// eslint-disable-next-line import/no-extraneous-dependencies
import { vi } from 'vitest'

/** Viewport width tests render at, so antd responsive columns behave like a wide desktop. */
const TEST_VIEWPORT_WIDTH = 1600

/** Reports whether a media query's min-width condition holds at the test viewport width. */
function matchesTestViewport(query: string): boolean {
  const minWidth = /min-width:\s*(\d+)px/.exec(query)
  return minWidth !== null && Number(minWidth[1]) <= TEST_VIEWPORT_WIDTH
}

Object.defineProperty(window, 'matchMedia', {
  writable: true,
  value: vi.fn().mockImplementation((query: string) => ({
    matches: matchesTestViewport(query),
    media: query,
    onchange: null,
    addListener: vi.fn(),
    removeListener: vi.fn(),
    addEventListener: vi.fn(),
    removeEventListener: vi.fn(),
    dispatchEvent: vi.fn()
  }))
})

Object.defineProperty(window, 'scrollTo', { value: () => {}, writable: true })
