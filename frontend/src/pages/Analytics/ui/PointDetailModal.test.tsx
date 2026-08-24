import type * as ReactRouterDom from 'react-router-dom'
import { renderWithProviders } from '@config/test-utils'
import { screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import PointDetailModal from './PointDetailModal'

const navigateMock = vi.fn()
vi.mock('react-router-dom', async importOriginal => ({
  ...(await importOriginal<typeof ReactRouterDom>()),
  useNavigate: () => navigateMock
}))

describe('PointDetailModal', () => {
  beforeEach(() => {
    navigateMock.mockClear()
  })

  it('renders the title and every metric row when open', () => {
    renderWithProviders(
      <PointDetailModal
        open
        title="Aug 20"
        metrics={[
          { label: 'Total', value: '1,284', color: '#4f46e5' },
          { label: 'Accepted rate', value: '92.0%' }
        ]}
        onClose={vi.fn()}
      />
    )

    expect(screen.getByText('Aug 20')).toBeInTheDocument()
    expect(screen.getByText('Total')).toBeInTheDocument()
    expect(screen.getByText('1,284')).toBeInTheDocument()
    expect(screen.getByText('Accepted rate')).toBeInTheDocument()
    expect(screen.getByText('92.0%')).toBeInTheDocument()
  })

  it('renders nothing when closed', () => {
    renderWithProviders(
      <PointDetailModal
        open={false}
        title="Aug 20"
        metrics={[{ label: 'Total', value: '1,284' }]}
        onClose={vi.fn()}
      />
    )

    expect(screen.queryByText('Aug 20')).not.toBeInTheDocument()
  })

  it('calls onClose when the modal is dismissed', async () => {
    const user = userEvent.setup()
    const onClose = vi.fn()
    renderWithProviders(
      <PointDetailModal
        open
        title="Aug 20"
        metrics={[{ label: 'Total', value: '1,284' }]}
        onClose={onClose}
      />
    )

    await user.click(screen.getByRole('button', { name: 'Close' }))

    expect(onClose).toHaveBeenCalledTimes(1)
  })

  it('renders no "View in logs" button when logsFilter is not provided', () => {
    renderWithProviders(
      <PointDetailModal
        open
        title="Aug 20"
        metrics={[{ label: 'Total', value: '1,284' }]}
        onClose={vi.fn()}
      />
    )

    expect(screen.queryByRole('button', { name: 'View in logs' })).not.toBeInTheDocument()
  })

  it('navigates to the filtered Logs page and closes when "View in logs" is clicked', async () => {
    const user = userEvent.setup()
    const onClose = vi.fn()
    renderWithProviders(
      <PointDetailModal
        open
        title="Aug 20"
        metrics={[{ label: 'Total', value: '1,284' }]}
        onClose={onClose}
        logsFilter={{ delivery_status: 'bounced', date_from: '2026-08-20', date_to: '2026-08-20' }}
      />
    )

    await user.click(screen.getByRole('button', { name: 'View in logs' }))

    expect(navigateMock).toHaveBeenCalledWith(
      '/logs?delivery_status=bounced&date_from=2026-08-20&date_to=2026-08-20'
    )
    expect(onClose).toHaveBeenCalledTimes(1)
  })
})
