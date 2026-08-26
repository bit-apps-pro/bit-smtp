import { type LogType } from '@pages/Logs/data/useFetchLogs'
import { render, screen } from '@testing-library/react'
import { describe, expect, it } from 'vitest'
import DeliveryStatusTab from './DeliveryStatusTab'

const log: LogType = {
  id: 57,
  status: '1',
  subject: 'Gmail E2E',
  to_addr: ['recipient@example.com'],
  details: { message: 'Body', headers: {}, attachments: [] },
  debug_info: [],
  retry_count: 0,
  connection: 'Gmail',
  delivery_status: 'delivered',
  created_at: '2026-07-24T18:19:46Z',
  updated_at: '2026-07-24T18:19:46Z'
}

describe('DeliveryStatusTab', () => {
  it('lists per-message opens and clicks with the click target and confirmed-human count', () => {
    render(
      <DeliveryStatusTab
        log={{
          ...log,
          engagement: [
            {
              type: 'click',
              target: 'https://example.com/pricing',
              hits: 5,
              automated_hits: 2,
              first_at: '2026-07-24T18:20:00Z',
              last_at: '2026-07-24T18:25:00Z'
            }
          ]
        }}
      />
    )

    expect(screen.getByText('Engagement (opens & clicks)')).toBeInTheDocument()
    expect(screen.getByText('https://example.com/pricing')).toBeInTheDocument()
    // Confirmed-human = hits - automated_hits = 3.
    expect(screen.getByText('3')).toBeInTheDocument()
  })

  it('shows the tracking-required hint when no engagement was recorded', () => {
    render(<DeliveryStatusTab log={log} />)

    expect(
      screen.getByText(
        'No opens or clicks recorded. Engagement tracking must be enabled to capture these.'
      )
    ).toBeInTheDocument()
  })
})
