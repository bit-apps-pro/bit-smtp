import { renderWithProviders } from '@config/test-utils'
import { screen, within } from '@testing-library/react'
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
        },
        {
          id: 3,
          status: 1,
          subject: 'Backup',
          to_addr: ['c@example.org'],
          retry_count: 0,
          connection: 'Backup SMTP',
          delivery_verified: true,
          delivery_status: 'bounced',
          details: {
            attempts: [
              { connection: 'Primary SMTP', status: 'failed' },
              { connection: 'Backup SMTP', status: 'sent' }
            ]
          },
          created_at: ''
        }
      ],
      count: 3,
      current: 1,
      pages: 1
    }
  })
}))

/** The table row whose Subject cell reads `subject`. */
async function rowFor(subject: string): Promise<HTMLElement> {
  const cell = await screen.findByText(subject)
  const row = cell.closest('tr')
  if (!row) throw new Error(`No row for ${subject}`)
  return row
}

describe('Logs page result, delivery and connection columns', () => {
  it('shows a sent row as Sent with delivery Not tracked when the connection reports no delivery', async () => {
    renderWithProviders(<Logs />)

    const row = await rowFor('Hi')
    expect(within(row).getByText('Sent')).toBeInTheDocument()
    expect(within(row).getByText('Not tracked')).toBeInTheDocument()
    expect(within(row).getByText('Primary SMTP')).toBeInTheDocument()
  })

  it('shows a failed native send as Failed, dashing out delivery and connection', async () => {
    renderWithProviders(<Logs />)

    const row = await rowFor('Bye')
    expect(within(row).getByText('Failed')).toBeInTheDocument()
    expect(within(row).getAllByText('—')).toHaveLength(2)
  })

  it('shows a backup-delivered send as Sent via failover alongside its provider delivery status', async () => {
    renderWithProviders(<Logs />)

    const row = await rowFor('Backup')
    expect(within(row).getByText('Sent via failover')).toBeInTheDocument()
    expect(within(row).getByText('Bounced')).toBeInTheDocument()
    expect(within(row).queryByText(/failed/)).not.toBeInTheDocument()
  })

  it('explains the Result and Delivery values in header legends', () => {
    renderWithProviders(<Logs />)

    expect(screen.getByRole('button', { name: 'What Result values mean' })).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'What Delivery values mean' })).toBeInTheDocument()
  })
})
