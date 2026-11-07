import { renderWithProviders } from '@config/test-utils'
import { screen, waitFor } from '@testing-library/react'
import { afterEach, describe, expect, it, vi } from 'vitest'
import Logs from './Logs'

vi.mock('@common/helpers/request', () => ({
  default: vi.fn().mockResolvedValue({ status: 'success', code: 'SUCCESS', data: {} })
}))

/** Makes every element report the given rendered width, as the table wrapper measurement reads it. */
function stubMeasuredWidth(width: number) {
  vi.spyOn(HTMLElement.prototype, 'getBoundingClientRect').mockReturnValue({
    width,
    height: 0,
    top: 0,
    left: 0,
    right: width,
    bottom: 0,
    x: 0,
    y: 0,
    toJSON: () => ({})
  })
}

describe('Logs table fitted to its card width', () => {
  afterEach(() => vi.restoreAllMocks())

  it('shows Subject and scrolls only from its minimum width in a wide card', async () => {
    stubMeasuredWidth(1400)
    const { container } = renderWithProviders(<Logs />)

    await waitFor(() => expect(container.querySelector('.ant-table')).not.toBeNull())
    expect(screen.getAllByText('Subject').length).toBeGreaterThan(0)
    expect(container.querySelector('table')?.style.width).toBe('1208px')
  })

  it('hides Subject and keeps a scroll container on a phone-width card', async () => {
    stubMeasuredWidth(400)
    const { container } = renderWithProviders(<Logs />)

    await waitFor(() => expect(container.querySelector('.ant-table')).not.toBeNull())
    expect(screen.queryByText('Subject')).toBeNull()
    expect(container.querySelector('.ant-table-content')).toHaveStyle({ overflowX: 'auto' })
  })
})
