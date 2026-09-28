// eslint-disable-next-line import/no-extraneous-dependencies
import '@testing-library/jest-dom/vitest'
// eslint-disable-next-line import/no-extraneous-dependencies
import { vi } from 'vitest'

Object.defineProperty(window, 'matchMedia', {
  writable: true,
  value: vi.fn().mockImplementation(query => ({
    matches: false,
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
