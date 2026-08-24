import { renderWithProviders } from '@config/test-utils'
import { screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { describe, expect, it } from 'vitest'
import ChartCard from './ChartCard'

/** Renders ChartCard with distinguishable chart/table fixtures so each view can be asserted on by text. */
function renderCard() {
  return renderWithProviders(
    <ChartCard title="Volume over time" tableView={<div>table-content</div>}>
      <div>chart-content</div>
    </ChartCard>
  )
}

describe('ChartCard', () => {
  it('shows the chart by default, with the table twin hidden', () => {
    renderCard()

    expect(screen.getByText('chart-content')).toBeInTheDocument()
    expect(screen.queryByText('table-content')).not.toBeInTheDocument()
  })

  it('clicking within the chart region does not switch to the table view', async () => {
    const user = userEvent.setup()
    renderCard()

    await user.click(screen.getByText('chart-content'))

    expect(screen.getByText('chart-content')).toBeInTheDocument()
    expect(screen.queryByText('table-content')).not.toBeInTheDocument()
  })

  it('the toggle button flips chart to table, and back again', async () => {
    const user = userEvent.setup()
    renderCard()

    await user.click(screen.getByRole('button', { name: /view as table/i }))
    expect(screen.getByText('table-content')).toBeInTheDocument()
    expect(screen.queryByText('chart-content')).not.toBeInTheDocument()

    await user.click(screen.getByRole('button', { name: /view chart/i }))
    expect(screen.getByText('chart-content')).toBeInTheDocument()
    expect(screen.queryByText('table-content')).not.toBeInTheDocument()
  })

  it('clicking within the table does not flip back to the chart', async () => {
    const user = userEvent.setup()
    renderCard()

    await user.click(screen.getByRole('button', { name: /view as table/i }))
    expect(screen.getByText('table-content')).toBeInTheDocument()

    await user.click(screen.getByText('table-content'))

    expect(screen.getByText('table-content')).toBeInTheDocument()
    expect(screen.queryByText('chart-content')).not.toBeInTheDocument()
  })
})
