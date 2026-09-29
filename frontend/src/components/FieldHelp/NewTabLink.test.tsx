import { render, screen } from '@testing-library/react'
import { describe, expect, it } from 'vitest'
import NewTabLink from './NewTabLink'

describe('NewTabLink', () => {
  it('opens safely in a new tab and tells screen readers so', () => {
    render(<NewTabLink href="https://example.com/docs">Learn more</NewTabLink>)

    const link = screen.getByRole('link', { name: 'Learn more (opens in a new tab)' })
    expect(link).toHaveAttribute('href', 'https://example.com/docs')
    expect(link).toHaveAttribute('target', '_blank')
    expect(link).toHaveAttribute('rel', 'noopener noreferrer')
  })

  it('marks the new-tab icon as decorative text, not an emoji arrow', () => {
    render(<NewTabLink href="https://example.com/docs">Learn more</NewTabLink>)

    expect(screen.getByRole('link')).not.toHaveTextContent('↗')
    expect(screen.getByRole('link').querySelector('.anticon')).toHaveAttribute('aria-hidden', 'true')
  })
})
