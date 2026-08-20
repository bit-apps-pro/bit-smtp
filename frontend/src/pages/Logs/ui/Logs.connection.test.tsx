import { renderWithProviders } from '@config/test-utils'
import { screen } from '@testing-library/react'
import { describe, expect, it, vi } from 'vitest'
import Logs from './Logs'

vi.mock('@common/helpers/request', () => ({
  default: vi.fn().mockResolvedValue({
    status: 'success',
    code: 'SUCCESS',
    data: {
      logs: [
        {
          id: 1,
          status: 1,
          subject: 'Hi',
          to_addr: ['a@example.org'],
          retry_count: 0,
          connection: 'Primary SMTP',
          created_at: ''
        },
        {
          id: 2,
          status: 0,
          subject: 'Bye',
          to_addr: ['b@example.org'],
          retry_count: 1,
          connection: null,
          created_at: ''
        }
      ],
      count: 2,
      current: 1,
      pages: 1
    }
  })
}))

describe('Logs page connection column', () => {
  it('shows the connection that handled each send, dashing out native sends', async () => {
    renderWithProviders(<Logs />)

    expect(await screen.findByText('Primary SMTP')).toBeInTheDocument()
    expect(screen.getByText('—')).toBeInTheDocument()
  })
})
