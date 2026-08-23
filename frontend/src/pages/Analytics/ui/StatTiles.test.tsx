import { renderWithProviders } from '@config/test-utils'
import { type Overview } from '@pages/Analytics/types'
import { screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { describe, expect, it } from 'vitest'
import StatTiles from './StatTiles'

/** Builds a minimal, valid Overview fixture; only `delivery` varies per test. */
function overviewFixture(overrides: Partial<Overview> = {}): Overview {
  return {
    range: { start: '2026-01-01T00:00:00Z', end: '2026-01-31T23:59:59Z' },
    timezone: 'UTC',
    total: 120,
    unknown_source_count: 0,
    unknown_recipient_count: 0,
    interpretation: '',
    logging_enabled: true,
    retained_records: { earliest: null, latest: null },
    timestamp_coverage: { qualified_records: 120, unqualified_records: 0, interpretation: '' },
    recipients: 100,
    acceptance: { accepted: 110, failed: 10, denominator: 120, accepted_rate: 91.67 },
    delivery: {
      delivered: 90,
      delayed: 5,
      bounced: 3,
      blocked: 1,
      spam: 1,
      accepted: 10,
      pending: 0,
      unknown: 0,
      denominator: 100,
      delivered_rate: 90
    },
    busiest_hours: [],
    series: [],
    top_sources: [],
    top_connections: [],
    ...overrides
  }
}

describe('StatTiles', () => {
  it('renders the delivered rate as a formatted percentage when there are webhook-confirmed deliveries', () => {
    renderWithProviders(<StatTiles overview={overviewFixture()} />)

    expect(screen.getByText('90.0%')).toBeInTheDocument()
  })

  it('shows a neutral "—" caveat instead of a misleading 0.0% when there are no delivery confirmations yet', async () => {
    const user = userEvent.setup()
    renderWithProviders(
      <StatTiles
        overview={overviewFixture({
          delivery: {
            delivered: 0,
            delayed: 0,
            bounced: 0,
            blocked: 0,
            spam: 0,
            accepted: 0,
            pending: 0,
            unknown: 0,
            denominator: 0,
            delivered_rate: 0
          }
        })}
      />
    )

    expect(screen.getByText('—')).toBeInTheDocument()
    expect(screen.queryByText('0.0%')).not.toBeInTheDocument()

    await user.hover(screen.getByText('—'))
    expect(await screen.findByText(/No delivery confirmations yet/)).toBeInTheDocument()
  })
})
