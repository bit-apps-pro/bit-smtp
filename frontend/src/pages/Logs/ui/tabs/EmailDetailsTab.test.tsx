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
})
