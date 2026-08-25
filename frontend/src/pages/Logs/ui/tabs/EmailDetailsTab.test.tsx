import { type LogType } from '@pages/Logs/data/useFetchLogs'
import { render, screen } from '@testing-library/react'
import { describe, expect, it } from 'vitest'
import EmailDetailsTab from './EmailDetailsTab'

const log: LogType = {
  id: 57,
  status: '1',
  subject: 'Gmail E2E',
  to_addr: ['recipient@example.com'],
  details: { message: 'Body', headers: {}, attachments: [] },
  debug_info: [],
  retry_count: 0,
  connection: 'Gmail',
  message_id: 'gmail-message-123',
  created_at: '2026-07-24T18:19:46Z',
  updated_at: '2026-07-24T18:19:46Z'
}

describe('EmailDetailsTab', () => {
  it('shows the provider message id returned by the sending API', () => {
    render(<EmailDetailsTab log={log} />)

    expect(screen.getByText('Provider Message ID:')).toBeInTheDocument()
    expect(screen.getByText('gmail-message-123')).toBeInTheDocument()
  })

  it('shows the connection-applied sender', () => {
    render(<EmailDetailsTab log={{ ...log, sender: 'Store <store@example.com>' }} />)

    expect(screen.getByText('Store <store@example.com>')).toBeInTheDocument()
  })

  it('shows a placeholder when no sender was captured', () => {
    render(<EmailDetailsTab log={log} />)

    expect(screen.getByText('—')).toBeInTheDocument()
  })

  it('shows which log a resend was launched from', () => {
    render(<EmailDetailsTab log={{ ...log, resend_of: 42 }} />)

    expect(screen.getByText('Resent from:')).toBeInTheDocument()
    expect(screen.getByText('#42')).toBeInTheDocument()
  })

  it('lists the resends launched from this log', () => {
    render(
      <EmailDetailsTab
        log={{
          ...log,
          resends: [
            { id: 91, status: 'sent', created_at: '2026-07-25T10:00:00Z' },
            { id: 92, status: 'failed', created_at: '2026-07-26T11:00:00Z' }
          ]
        }}
      />
    )

    expect(screen.getByText('Resends:')).toBeInTheDocument()
    expect(screen.getByText(/#91/)).toBeInTheDocument()
    expect(screen.getByText(/sent/)).toBeInTheDocument()
    expect(screen.getByText(/#92/)).toBeInTheDocument()
    expect(screen.getByText(/failed/)).toBeInTheDocument()
  })

  it('omits the resend history when there is none', () => {
    render(<EmailDetailsTab log={log} />)

    expect(screen.queryByText('Resent from:')).not.toBeInTheDocument()
    expect(screen.queryByText('Resends:')).not.toBeInTheDocument()
  })
})
