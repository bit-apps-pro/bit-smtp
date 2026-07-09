import { renderWithProviders } from '@config/test-utils'
import { describe, expect, it, vi } from 'vitest'
import MailSendTest from './MailSendTest'

vi.mock('@common/helpers/request', () => ({
  default: vi.fn().mockResolvedValue({ status: 'success', code: 'SUCCESS', data: {} })
}))

describe('MailSendTest page', () => {
  it('renders without throwing after the frontend upgrade', () => {
    expect(() => renderWithProviders(<MailSendTest />)).not.toThrow()
  })
})
