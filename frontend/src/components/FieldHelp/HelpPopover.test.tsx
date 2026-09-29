import { fireEvent, render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { describe, expect, it } from 'vitest'
import HelpPopover from './HelpPopover'

const DETAILS = {
  title: 'Retry timing',
  body: 'How long to wait before trying again.',
  example: '1 min, then 5 min',
  docUrl: 'https://example.com/retry'
}

/** Renders the popover with full details and returns its trigger button. */
function renderPopover() {
  render(<HelpPopover label="Help: Retry" details={DETAILS} />)
  return screen.getByRole('button', { name: 'Help: Retry' })
}

describe('HelpPopover', () => {
  it('starts collapsed and opens on click with title, body, example and doc link', async () => {
    const trigger = renderPopover()
    expect(trigger).toHaveAttribute('aria-expanded', 'false')

    fireEvent.click(trigger)

    expect(await screen.findByText('Retry timing')).toBeInTheDocument()
    expect(screen.getByText('How long to wait before trying again.')).toBeInTheDocument()
    expect(screen.getByText('1 min, then 5 min')).toBeInTheDocument()
    const link = screen.getByRole('link', { name: /Learn more/ })
    expect(link).toHaveAttribute('href', DETAILS.docUrl)
    expect(link).toHaveAttribute('target', '_blank')
    expect(link).toHaveAttribute('rel', 'noopener noreferrer')
    expect(trigger).toHaveAttribute('aria-expanded', 'true')
  })

  it('opens from the keyboard', async () => {
    const user = userEvent.setup()
    const trigger = renderPopover()

    await user.tab()
    expect(trigger).toHaveFocus()
    await user.keyboard('{Enter}')

    expect(await screen.findByText('Retry timing')).toBeInTheDocument()
    expect(trigger).toHaveAttribute('aria-expanded', 'true')
  })

  it('closes on Escape and returns focus to the trigger', async () => {
    const user = userEvent.setup()
    const trigger = renderPopover()
    await user.click(trigger)
    await screen.findByText('Retry timing')
    trigger.blur()

    await user.keyboard('{Escape}')

    await waitFor(() => expect(trigger).toHaveAttribute('aria-expanded', 'false'))
    expect(trigger).toHaveFocus()
  })

  it('omits the example and link when not provided', async () => {
    render(<HelpPopover label="Help" details={{ title: 'Title', body: 'Body' }} />)
    fireEvent.click(screen.getByRole('button', { name: 'Help' }))

    await screen.findByText('Title')
    expect(screen.queryByText(/Example/)).not.toBeInTheDocument()
    expect(screen.queryByRole('link')).not.toBeInTheDocument()
  })
})
