import type * as ReactRouterDom from 'react-router-dom'
import { renderWithProviders } from '@config/test-utils'
import { type DimensionGroup } from '@pages/Analytics/types'
import { screen, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import TopList, { buildTopListRowMetrics } from './TopList'

const navigateMock = vi.fn()
vi.mock('react-router-dom', async importOriginal => ({
  ...(await importOriginal<typeof ReactRouterDom>()),
  useNavigate: () => navigateMock
}))

/** Builds a minimal DimensionGroup fixture, only the fields under test vary per call. */
function group(overrides: Partial<DimensionGroup>): DimensionGroup {
  return {
    dimension: 'source-a',
    total: 10,
    accepted: 9,
    failed: 1,
    delivered: 8,
    verified_delivery: 9,
    ...overrides
  }
}

describe('buildTopListRowMetrics', () => {
  it('lists the name and every count field of the row', () => {
    const metrics = buildTopListRowMetrics({
      dimension: 'smtp.example.com',
      total: 120,
      accepted: 110,
      delivered: 100,
      failed: 10,
      isOther: false
    })

    expect(metrics).toEqual([
      { label: 'Name', value: 'smtp.example.com' },
      { label: 'Total', value: '120' },
      { label: 'Accepted', value: '110' },
      { label: 'Delivered', value: '100' },
      { label: 'Failed', value: '10' }
    ])
  })
})

describe('TopList', () => {
  beforeEach(() => {
    navigateMock.mockClear()
  })

  it('clicking a row opens the detail modal with that row full data', async () => {
    const user = userEvent.setup()
    renderWithProviders(
      <TopList
        title="Top sources"
        emptyLabel="No source activity in this range."
        groups={[
          group({ dimension: 'smtp.example.com', total: 120, accepted: 110, delivered: 100, failed: 10 })
        ]}
        logsFilterKey="source_plugin"
        dateFrom="2026-08-01"
        dateTo="2026-08-20"
      />
    )

    await user.click(screen.getByText('smtp.example.com'))

    const dialog = screen.getByRole('dialog')
    expect(dialog).toBeInTheDocument()
    expect(within(dialog).getByText('120')).toBeInTheDocument()
    expect(within(dialog).getByText('110')).toBeInTheDocument()
    expect(within(dialog).getByText('100')).toBeInTheDocument()
    expect(within(dialog).getByText('10')).toBeInTheDocument()
  })

  it('pressing Enter on a focused row also opens the detail modal', async () => {
    const user = userEvent.setup()
    renderWithProviders(
      <TopList
        title="Top sources"
        emptyLabel="No source activity in this range."
        groups={[group({ dimension: 'smtp.example.com' })]}
        logsFilterKey="source_plugin"
        dateFrom="2026-08-01"
        dateTo="2026-08-20"
      />
    )

    screen.getByRole('button', { name: /smtp\.example\.com/ }).focus()
    await user.keyboard('{Enter}')

    expect(screen.getByRole('dialog')).toBeInTheDocument()
  })

  it('the folded "Other" row is clickable too, showing its aggregated totals', async () => {
    const user = userEvent.setup()
    const groups = Array.from({ length: 10 }, (_, index) =>
      group({ dimension: `source-${index}`, total: 1, accepted: 1, delivered: 1, failed: 0 })
    )
    renderWithProviders(
      <TopList
        title="Top sources"
        emptyLabel="No source activity in this range."
        groups={groups}
        logsFilterKey="source_plugin"
        dateFrom="2026-08-01"
        dateTo="2026-08-20"
      />
    )

    await user.click(screen.getByText('Other'))

    const dialog = screen.getByRole('dialog')
    // 8 visible rows fold the remaining 2 groups into "Other": total 2, accepted 2, delivered 2, failed 0.
    expect(within(dialog).getAllByText('Other').length).toBeGreaterThanOrEqual(1)
    expect(within(dialog).getAllByText('2')).toHaveLength(3)
    expect(within(dialog).getByText('0')).toBeInTheDocument()
  })

  it('"View in logs" navigates to Logs scoped to the clicked dimension and the given date range', async () => {
    const user = userEvent.setup()
    renderWithProviders(
      <TopList
        title="Top connections"
        emptyLabel="No connection activity in this range."
        groups={[group({ dimension: 'conn_1' })]}
        logsFilterKey="connection_id"
        dateFrom="2026-08-01"
        dateTo="2026-08-20"
      />
    )

    await user.click(screen.getByText('conn_1'))
    await user.click(screen.getByRole('button', { name: 'View in logs' }))

    expect(navigateMock).toHaveBeenCalledWith(
      '/logs?connection_id=conn_1&date_from=2026-08-01&date_to=2026-08-20'
    )
  })

  it('does not offer "View in logs" for the folded "Other" row', async () => {
    const user = userEvent.setup()
    const groups = Array.from({ length: 10 }, (_, index) =>
      group({ dimension: `source-${index}`, total: 1, accepted: 1, delivered: 1, failed: 0 })
    )
    renderWithProviders(
      <TopList
        title="Top sources"
        emptyLabel="No source activity in this range."
        groups={groups}
        logsFilterKey="source_plugin"
        dateFrom="2026-08-01"
        dateTo="2026-08-20"
      />
    )

    await user.click(screen.getByText('Other'))

    expect(screen.queryByRole('button', { name: 'View in logs' })).not.toBeInTheDocument()
  })

  it('does not offer "View in logs" for an "unknown" dimension', async () => {
    const user = userEvent.setup()
    renderWithProviders(
      <TopList
        title="Top sources"
        emptyLabel="No source activity in this range."
        groups={[group({ dimension: 'unknown' })]}
        logsFilterKey="source_plugin"
        dateFrom="2026-08-01"
        dateTo="2026-08-20"
      />
    )

    await user.click(screen.getByText('unknown'))

    expect(screen.queryByRole('button', { name: 'View in logs' })).not.toBeInTheDocument()
  })
})
