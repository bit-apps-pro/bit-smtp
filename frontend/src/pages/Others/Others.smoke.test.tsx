import { renderWithProviders } from '@config/test-utils'
import { describe, expect, it, vi } from 'vitest'
import Others from './Others'

vi.mock('@common/helpers/request', () => ({
  default: vi.fn().mockResolvedValue({ status: 'success', code: 'SUCCESS', data: {} })
}))

describe('Others page', () => {
  it('renders without throwing after the frontend upgrade', () => {
    expect(() => renderWithProviders(<Others />)).not.toThrow()
  })
})
