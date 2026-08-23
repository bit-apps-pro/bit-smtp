import { renderWithProviders } from '@config/test-utils'
import { useAnomalies, useDeliverability, useOverview } from '@pages/Analytics/data/useAnalytics'
import { type Anomalies, type Deliverability, type Overview } from '@pages/Analytics/types'
import { screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { type Mock, beforeEach, describe, expect, it, vi } from 'vitest'
import AnalyticsPage from './AnalyticsPage'

// Keep the real analyticsQueryState/AnalyticsApiError - only the network-touching hooks are stubbed,
// so the page's loading/error/logging-off/ready branching runs for real against these mocks.
vi.mock('@pages/Analytics/data/useAnalytics', async importOriginal => {
  const actual = await importOriginal()
  return {
    ...(actual as object),
    useOverview: vi.fn(),
    useDeliverability: vi.fn(),
    useAnomalies: vi.fn()
  }
})

const overviewFixture: Overview = {
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
  busiest_hours: [{ hour: 9, label: '09:00', total: 20 }],
  series: [
    {
      bucket: '2026-01-01',
      label: '2026-01-01',
      total: 10,
      accepted: 9,
      failed: 1,
      delivered: 8,
      verified_delivery: 9
    },
    {
      bucket: '2026-01-02',
      label: '2026-01-02',
      total: 12,
      accepted: 11,
      failed: 1,
      delivered: 9,
      verified_delivery: 10
    }
  ],
  top_sources: [
    { dimension: 'wordpress', total: 60, accepted: 55, failed: 5, delivered: 50, verified_delivery: 55 }
  ],
  top_connections: [
    { dimension: 'conn_1', total: 120, accepted: 110, failed: 10, delivered: 90, verified_delivery: 100 }
  ]
}

const deliverabilityFixture: Deliverability = {
  range: overviewFixture.range,
  timezone: 'UTC',
  total: 120,
  acceptance: overviewFixture.acceptance,
  delivery: overviewFixture.delivery,
  sources: overviewFixture.top_sources,
  connections: overviewFixture.top_connections
}

const anomaliesFixture: Anomalies = {
  range: overviewFixture.range,
  timezone: 'UTC',
  total: 120,
  current: { total: 120, accepted: 110, failed: 10 },
  prior: { total: 100, accepted: 95, failed: 5 },
  prior_range: overviewFixture.range,
  timestamp_coverage: overviewFixture.timestamp_coverage,
  comparison_coverage: {
    complete: true,
    retained_from: null,
    continuity_from: null,
    configured_retained_from: null
  },
  observations: [{ type: 'volume_change', current: 120, prior: 100, percentage_change: 20 }]
}

function readyResult<T>(data: T) {
  return { isPending: false, isError: false, error: null, data: { loggingDisabled: false, data } }
}

describe('AnalyticsPage', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    ;(useOverview as Mock).mockReturnValue(readyResult(overviewFixture))
    ;(useDeliverability as Mock).mockReturnValue(readyResult(deliverabilityFixture))
    ;(useAnomalies as Mock).mockReturnValue(readyResult(anomaliesFixture))
  })

  it('renders stat tiles and the chart grid from a fixture overview', () => {
    renderWithProviders(<AnalyticsPage />)

    expect(screen.getByText('Total sent')).toBeInTheDocument()
    expect(screen.getByText('Accepted rate')).toBeInTheDocument()
    expect(screen.getByText('Delivered rate')).toBeInTheDocument()
    expect(screen.getByText('Failed')).toBeInTheDocument()
    expect(screen.getByText('Volume over time')).toBeInTheDocument()
    expect(screen.getByText('Delivery breakdown')).toBeInTheDocument()
    expect(screen.getByText('Top sources')).toBeInTheDocument()
    expect(screen.getByText('Top connections')).toBeInTheDocument()
    expect(screen.getByText('Busiest hours')).toBeInTheDocument()
    expect(screen.getByText('Anomalies')).toBeInTheDocument()
  })

  it('renders the "enable logging" empty state, not an error, when code is bit_smtp_logging_disabled', () => {
    ;(useOverview as Mock).mockReturnValue({
      isPending: false,
      isError: false,
      error: null,
      data: { loggingDisabled: true }
    })

    renderWithProviders(<AnalyticsPage />)

    expect(screen.getByText('Enable logging to see analytics')).toBeInTheDocument()
    expect(screen.queryByText('Could not load analytics')).not.toBeInTheDocument()
    expect(screen.queryByText('Total sent')).not.toBeInTheDocument()
  })

  it('requests a new bucket - and therefore a new query key - when the filter changes', async () => {
    const user = userEvent.setup()
    renderWithProviders(<AnalyticsPage />)

    expect((useOverview as Mock).mock.calls.at(-1)?.[0].bucket).toBeUndefined()

    await user.click(screen.getByText('Day'))

    expect((useOverview as Mock).mock.calls.at(-1)?.[0].bucket).toBe('day')
    expect((useDeliverability as Mock).mock.calls.at(-1)?.[0].bucket).toBe('day')
    expect((useAnomalies as Mock).mock.calls.at(-1)?.[0].bucket).toBe('day')
  })
})
