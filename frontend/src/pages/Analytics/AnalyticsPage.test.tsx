import { renderWithProviders } from '@config/test-utils'
import { AnalyticsApiError, useAnomalies, useOverview } from '@pages/Analytics/data/useAnalytics'
import { type Anomalies, type Overview } from '@pages/Analytics/types'
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

  it('renders Top sources/Top connections straight from the overview response - no separate deliverability query', () => {
    renderWithProviders(<AnalyticsPage />)

    // Only overviewFixture.top_sources/top_connections feed these panels; there is no deliverability
    // fixture in this suite at all, so this content can only have come from the overview response.
    expect(screen.getByText('wordpress')).toBeInTheDocument()
    expect(screen.getByText('conn_1')).toBeInTheDocument()
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

  it('renders the no-data empty state, not the charts, when the range has zero sends', () => {
    ;(useOverview as Mock).mockReturnValue(readyResult({ ...overviewFixture, total: 0 }))

    renderWithProviders(<AnalyticsPage />)

    expect(screen.getByText('No sends in this range. Try widening the date range.')).toBeInTheDocument()
    expect(screen.queryByText('Volume over time')).not.toBeInTheDocument()
    expect(screen.queryByText('Delivery breakdown')).not.toBeInTheDocument()
    expect(screen.queryByText('Anomalies')).not.toBeInTheDocument()
    // Stat tiles (all zero) still render above the empty state - they read the total directly.
    expect(screen.getByText('Total sent')).toBeInTheDocument()
  })

  it('requests a new bucket - and therefore a new query key - when the filter changes', async () => {
    const user = userEvent.setup()
    renderWithProviders(<AnalyticsPage />)

    expect((useOverview as Mock).mock.calls.at(-1)?.[0].bucket).toBeUndefined()

    await user.click(screen.getByText('Day'))

    expect((useOverview as Mock).mock.calls.at(-1)?.[0].bucket).toBe('day')
    expect((useAnomalies as Mock).mock.calls.at(-1)?.[0].bucket).toBe('day')
  })

  it('renders an inline error with a retry option for the anomalies panel - not an infinite spinner - when its query fails', async () => {
    const refetch = vi.fn()
    ;(useAnomalies as Mock).mockReturnValue({
      isPending: false,
      isError: true,
      error: new AnalyticsApiError('bit_smtp_analytics_error', 'Could not compute anomalies.'),
      data: undefined,
      refetch
    })

    const user = userEvent.setup()
    const { container } = renderWithProviders(<AnalyticsPage />)

    expect(screen.getByText('Could not load analytics')).toBeInTheDocument()
    expect(screen.getByText('Could not compute anomalies.')).toBeInTheDocument()
    expect(container.querySelector('.ant-spin')).not.toBeInTheDocument()

    await user.click(screen.getByRole('button', { name: 'Retry' }))
    expect(refetch).toHaveBeenCalledTimes(1)
  })

  it('still shows the volume/delivery/ranking panels while only the anomalies query is loading', () => {
    ;(useAnomalies as Mock).mockReturnValue({
      isPending: true,
      isError: false,
      error: null,
      data: undefined
    })

    const { container } = renderWithProviders(<AnalyticsPage />)

    expect(screen.getByText('Volume over time')).toBeInTheDocument()
    expect(screen.getByText('Top sources')).toBeInTheDocument()
    expect(container.querySelector('.ant-spin')).toBeInTheDocument()
    expect(screen.queryByText('Could not load analytics')).not.toBeInTheDocument()
  })
})
