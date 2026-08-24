import type * as ReactRouterDom from 'react-router-dom'
import { renderWithProviders } from '@config/test-utils'
import { type DeliveryStats } from '@pages/Analytics/types'
import { screen, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import DeliveryBreakdown, { buildDeliveryRowMetrics } from './DeliveryBreakdown'

const navigateMock = vi.fn()
vi.mock('react-router-dom', async importOriginal => ({
  ...(await importOriginal<typeof ReactRouterDom>()),
  useNavigate: () => navigateMock
}))

const delivery: DeliveryStats = {
  delivered: 80,
  delayed: 5,
  bounced: 3,
  blocked: 1,
  spam: 1,
  accepted: 10,
  pending: 0,
  unknown: 0,
  denominator: 90,
  delivered_rate: 88.9
}

describe('buildDeliveryRowMetrics', () => {
  it('reports status, count, and share of confirmed outcomes', () => {
    const metrics = buildDeliveryRowMetrics(
      { key: 'delivered', label: 'Delivered', value: 80, color: '#0ca30c' },
      90
    )

    expect(metrics).toEqual([
      { label: 'Status', value: 'Delivered', color: '#0ca30c' },
      { label: 'Count', value: '80' },
      { label: 'Share of confirmed outcomes', value: '88.9%' }
    ])
  })

  it('shows an em dash for the share when the denominator is zero', () => {
    const metrics = buildDeliveryRowMetrics(
      { key: 'pending', label: 'Pending', value: 0, color: '#898781' },
      0
    )

    expect(metrics[2]).toEqual({ label: 'Share of confirmed outcomes', value: '—' })
  })
})

describe('DeliveryBreakdown', () => {
  beforeEach(() => {
    navigateMock.mockClear()
  })

  it('clicking a row opens the detail modal with that status full data', async () => {
    const user = userEvent.setup()
    renderWithProviders(
      <DeliveryBreakdown delivery={delivery} dateFrom="2026-08-01" dateTo="2026-08-20" />
    )

    await user.click(screen.getByText('Delivered'))

    const dialog = screen.getByRole('dialog')
    expect(dialog).toBeInTheDocument()
    expect(within(dialog).getByText('Status')).toBeInTheDocument()
    expect(within(dialog).getByText('Count')).toBeInTheDocument()
    expect(within(dialog).getByText('80')).toBeInTheDocument()
    expect(within(dialog).getByText('88.9%')).toBeInTheDocument()
  })

  it('pressing Enter on a focused row also opens the detail modal', async () => {
    const user = userEvent.setup()
    renderWithProviders(
      <DeliveryBreakdown delivery={delivery} dateFrom="2026-08-01" dateTo="2026-08-20" />
    )

    screen.getByRole('button', { name: /bounced/i }).focus()
    await user.keyboard('{Enter}')

    expect(screen.getByRole('dialog')).toBeInTheDocument()
  })

  it('"View in logs" navigates to Logs scoped to the clicked status and the given date range', async () => {
    const user = userEvent.setup()
    renderWithProviders(
      <DeliveryBreakdown delivery={delivery} dateFrom="2026-08-01" dateTo="2026-08-20" />
    )

    await user.click(screen.getByText('Bounced'))
    await user.click(screen.getByRole('button', { name: 'View in logs' }))

    expect(navigateMock).toHaveBeenCalledWith(
      '/logs?delivery_status=bounced&date_from=2026-08-01&date_to=2026-08-20'
    )
  })
})
