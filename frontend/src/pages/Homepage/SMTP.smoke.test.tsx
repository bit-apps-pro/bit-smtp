import { renderWithProviders } from '@config/test-utils'
import { describe, expect, it, vi } from 'vitest'
import SMTP from './SMTP'

vi.mock('@common/helpers/request', () => ({
  default: vi.fn().mockResolvedValue({ status: 'success', code: 'SUCCESS', data: {} })
}))

describe('SMTP page', () => {
  it('renders without throwing after the frontend upgrade', () => {
    expect(() => renderWithProviders(<SMTP />)).not.toThrow()
  })
})
