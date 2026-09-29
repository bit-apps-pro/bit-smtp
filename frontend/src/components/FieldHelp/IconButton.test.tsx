import { DeleteOutlined } from '@ant-design/icons'
import { fireEvent, render, screen, waitFor } from '@testing-library/react'
import { describe, expect, it, vi } from 'vitest'
import IconButton from './IconButton'

describe('IconButton', () => {
  it('uses its label as the accessible name and forwards button props', () => {
    const onClick = vi.fn()
    render(<IconButton icon={<DeleteOutlined />} label="Delete log" onClick={onClick} danger />)

    fireEvent.click(screen.getByRole('button', { name: 'Delete log' }))

    expect(onClick).toHaveBeenCalledTimes(1)
  })

  it('shows its label as a tooltip on hover', async () => {
    render(<IconButton icon={<DeleteOutlined />} label="Delete log" />)

    fireEvent.mouseEnter(screen.getByRole('button', { name: 'Delete log' }))

    expect(await screen.findByRole('tooltip')).toHaveTextContent('Delete log')
  })

  it('hides its tooltip once clicked, even while it keeps focus', async () => {
    render(<IconButton icon={<DeleteOutlined />} label="Delete log" />)
    const button = screen.getByRole('button', { name: 'Delete log' })

    fireEvent.mouseEnter(button)
    fireEvent.focus(button)
    const tooltip = await screen.findByRole('tooltip')
    fireEvent.click(button)

    await waitFor(() => expect(tooltip.closest('.ant-tooltip')).toHaveClass('ant-tooltip-hidden'))
  })
})
