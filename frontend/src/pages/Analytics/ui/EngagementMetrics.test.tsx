import { renderWithProviders } from '@config/test-utils'
import { type Engagement } from '@pages/Analytics/types'
import { screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { describe, expect, it } from 'vitest'
import EngagementMetrics from './EngagementMetrics'

/** Builds a minimal, valid Engagement fixture; callers override only the fields under test. */
function engagementFixture(overrides: Partial<Engagement> = {}): Engagement {
  return {
    range: { start: '2026-01-01T00:00:00Z', end: '2026-01-31T23:59:59Z' },
    timezone: 'UTC',
    total: 120,
    interpretation: '',
    opens: { total: 40, automated: 15, human: 25, unique: 30 },
    clicks: { total: 12, automated: 2, human: 10, unique: 8 },
    open_rate: { engaged_logs: 25, denominator: 100, rate: 25 },
    click_rate: { engaged_logs: 10, denominator: 100, rate: 10 },
    top_clicked_links: [],
    engagement_interpretation: 'Attributed per message, not per recipient.',
    ...overrides
  }
}

describe('EngagementMetrics', () => {
  it('surfaces the honest opens split - total, flagged automated, and confirmed human', () => {
    renderWithProviders(<EngagementMetrics engagement={engagementFixture()} />)

    expect(
      screen.getByText(/Opens: 40 \(15 flagged automated .* Confirmed human: 25\./)
    ).toBeInTheDocument()
    expect(screen.getByText('25.0%')).toBeInTheDocument()
  })

  it('flags the automated caveat on total opens instead of presenting a bare count', async () => {
    const user = userEvent.setup()
    renderWithProviders(<EngagementMetrics engagement={engagementFixture()} />)

    await user.hover(screen.getAllByText('40')[0])
    expect(await screen.findByText(/automated fires/i)).toBeInTheDocument()
  })

  it('renders the unique opened and clicked message counts in the table view', async () => {
    const user = userEvent.setup()
    renderWithProviders(<EngagementMetrics engagement={engagementFixture()} />)

    await user.click(screen.getByRole('button', { name: /view as table/i }))

    expect(screen.getByText('Unique opened messages')).toBeInTheDocument()
    expect(screen.getByText('30')).toBeInTheDocument()
    expect(screen.getByText('Unique clicked messages')).toBeInTheDocument()
    expect(screen.getByText('8')).toBeInTheDocument()
  })

  it('lists the top clicked links with their confirmed-human vs automated split', () => {
    renderWithProviders(
      <EngagementMetrics
        engagement={engagementFixture({
          top_clicked_links: [
            { target: 'https://example.com/pricing', total: 9, automated: 2, human: 7 }
          ]
        })}
      />
    )

    expect(screen.getByText('Top clicked links (confirmed-human vs automated)')).toBeInTheDocument()
    expect(screen.getByText('https://example.com/pricing')).toBeInTheDocument()
    expect(screen.getByText('7')).toBeInTheDocument()
  })

  it('omits the top-clicked-links table when no links were clicked', () => {
    renderWithProviders(<EngagementMetrics engagement={engagementFixture({ top_clicked_links: [] })} />)

    expect(
      screen.queryByText('Top clicked links (confirmed-human vs automated)')
    ).not.toBeInTheDocument()
  })

  it('shows a neutral "—" caveat for the open rate when nothing was accepted or delivered yet', async () => {
    const user = userEvent.setup()
    renderWithProviders(
      <EngagementMetrics
        engagement={engagementFixture({
          opens: { total: 0, automated: 0, human: 0, unique: 0 },
          clicks: { total: 0, automated: 0, human: 0, unique: 0 },
          open_rate: { engaged_logs: 0, denominator: 0, rate: 0 },
          click_rate: { engaged_logs: 0, denominator: 0, rate: 0 }
        })}
      />
    )

    expect(screen.queryByText('0.0%')).not.toBeInTheDocument()
    await user.hover(screen.getAllByText('—')[0])
    expect(await screen.findByText(/measure an open rate against/i)).toBeInTheDocument()
  })
})
