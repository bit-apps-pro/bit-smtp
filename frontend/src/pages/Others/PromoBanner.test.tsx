import config from '@config/config'
import { renderWithProviders } from '@config/test-utils'
import { screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import confetti from 'canvas-confetti'
import { afterEach, describe, expect, it, vi } from 'vitest'
import PromoBanner from './PromoBanner'

vi.mock('canvas-confetti', () => ({ default: vi.fn() }))

describe('PromoBanner', () => {
  afterEach(() => {
    config.AD_BUTTON = undefined
  })

  it('renders nothing outside a campaign', () => {
    config.AD_BUTTON = false
    const { container } = renderWithProviders(<PromoBanner />)

    expect(container).toBeEmptyDOMElement()
  })

  it('opens the campaign banner and respects reduced motion for the confetti', async () => {
    config.AD_BUTTON = { title: 'Big Deal', campaign: 'Big Deal', url: 'https://bitapps.pro' }
    renderWithProviders(<PromoBanner />)

    await userEvent.click(screen.getByRole('button', { name: 'Big Deal' }))

    expect(confetti).toHaveBeenCalledWith(expect.objectContaining({ disableForReducedMotion: true }))
    expect(await screen.findByRole('link')).toHaveAttribute(
      'href',
      'https://bitapps.pro/?utm_source=bit-smtp&utm_medium=inside-plugin&utm_campaign=Big+Deal'
    )
  })
})
