import { renderWithProviders } from '@config/test-utils'
import { type Anomalies, type ComparisonCoverage } from '@pages/Analytics/types'
import { screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { describe, expect, it } from 'vitest'
import AnomaliesList from './AnomaliesList'

const range = { start: '2026-01-01T00:00:00Z', end: '2026-01-31T23:59:59Z' }
const completeCoverage: ComparisonCoverage = {
  complete: true,
  retained_from: null,
  continuity_from: null,
  configured_retained_from: null
}

/** Builds a minimal, valid Anomalies fixture; only `observations`/`comparison_coverage` vary per test. */
function anomaliesFixture(overrides: Partial<Anomalies> = {}): Anomalies {
  return {
    range,
    timezone: 'UTC',
    total: 100,
    current: { total: 100, accepted: 90, failed: 10 },
    prior: { total: 80, accepted: 75, failed: 5 },
    prior_range: range,
    timestamp_coverage: { qualified_records: 100, unqualified_records: 0, interpretation: '' },
    comparison_coverage: completeCoverage,
    observations: [],
    ...overrides
  }
}

describe('AnomaliesList', () => {
  it('shows the insufficient-history state instead of cards when comparison coverage is incomplete', () => {
    renderWithProviders(
      <AnomaliesList
        anomalies={anomaliesFixture({ comparison_coverage: { ...completeCoverage, complete: false } })}
      />
    )

    expect(screen.getByText('Not enough history to compare yet.')).toBeInTheDocument()
  })

  it('shows the no-changes empty state when there are no observations', () => {
    renderWithProviders(<AnomaliesList anomalies={anomaliesFixture({ observations: [] })} />)

    expect(screen.getByText('No notable changes vs. the prior period.')).toBeInTheDocument()
  })

  it('maps every known severity to its reserved label, and a null severity to the Info fallback', () => {
    renderWithProviders(
      <AnomaliesList
        anomalies={anomaliesFixture({
          observations: [
            // failure_rate_change severity: <=0pp good, <10pp warning, >=10pp critical.
            {
              type: 'failure_rate_change',
              current_failure_rate: 5,
              prior_failure_rate: 10,
              percentage_point_change: -5
            },
            {
              type: 'failure_rate_change',
              current_failure_rate: 12,
              prior_failure_rate: 10,
              percentage_point_change: 2
            },
            {
              type: 'failure_rate_change',
              current_failure_rate: 30,
              prior_failure_rate: 10,
              percentage_point_change: 20
            },
            // volume_change is always informational (severity: null) - exercises the Info fallback branch.
            { type: 'volume_change', current: 120, prior: 100, percentage_change: 20 }
          ]
        })}
      />
    )

    expect(screen.getByText('Improved')).toBeInTheDocument()
    expect(screen.getByText('Watch')).toBeInTheDocument()
    expect(screen.getByText('Critical')).toBeInTheDocument()
    expect(screen.getByText('Info')).toBeInTheDocument()
  })

  it('renders a zero-point failure-rate change as informational (Info), never "Improved", and with no direction arrow', () => {
    const { container } = renderWithProviders(
      <AnomaliesList
        anomalies={anomaliesFixture({
          observations: [
            {
              type: 'failure_rate_change',
              current_failure_rate: 10,
              prior_failure_rate: 10,
              percentage_point_change: 0
            }
          ]
        })}
      />
    )

    expect(screen.getByText('Info')).toBeInTheDocument()
    expect(screen.queryByText('Improved')).not.toBeInTheDocument()
    expect(container.querySelector('.lucide-arrow-up-right')).not.toBeInTheDocument()
    expect(container.querySelector('.lucide-arrow-down-right')).not.toBeInTheDocument()
  })

  it('table view exposes the same observations as label — detail text, independent of the severity map', async () => {
    const user = userEvent.setup()
    renderWithProviders(
      <AnomaliesList
        anomalies={anomaliesFixture({
          observations: [{ type: 'volume_change', current: 120, prior: 100, percentage_change: 20 }]
        })}
      />
    )

    await user.click(screen.getByText('View as table'))

    expect(screen.getByText(/Send volume \+20\.0%/)).toBeInTheDocument()
  })
})
