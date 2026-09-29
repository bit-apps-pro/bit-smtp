import { DOCS } from '@common/docs'
import { renderWithProviders } from '@config/test-utils'
import { screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, describe, expect, it } from 'vitest'
import ReviewPrompt from './ReviewPrompt'
import { readOnboardingFlags, updateOnboardingFlags } from './onboardingStorage'

describe('ReviewPrompt', () => {
  afterEach(() => localStorage.clear())

  it('links to the plugin reviews page', () => {
    renderWithProviders(<ReviewPrompt />)

    expect(screen.getByRole('link', { name: /^Leave a review/ })).toHaveAttribute(
      'href',
      DOCS.pluginReviews
    )
  })

  it('remembers when it is closed', async () => {
    renderWithProviders(<ReviewPrompt />)

    await userEvent.click(screen.getByRole('button', { name: 'Hide review request' }))

    expect(readOnboardingFlags().reviewPromptDismissed).toBe(true)
  })

  it('renders nothing once dismissed', () => {
    updateOnboardingFlags({ reviewPromptDismissed: true })
    renderWithProviders(<ReviewPrompt />)

    expect(screen.queryByText('Is Bit SMTP working well for you?')).not.toBeInTheDocument()
  })
})
