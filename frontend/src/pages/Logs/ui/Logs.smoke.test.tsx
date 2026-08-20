import { renderWithProviders } from '@config/test-utils'
import { describe, expect, it, vi } from 'vitest'
import Logs from './Logs'

vi.mock('@common/helpers/request', () => ({
  default: vi.fn().mockResolvedValue({ status: 'success', code: 'SUCCESS', data: {} })
}))

describe('Logs page', () => {
  it('renders without throwing after the frontend upgrade', () => {
    expect(() => renderWithProviders(<Logs />)).not.toThrow()
  })
})
