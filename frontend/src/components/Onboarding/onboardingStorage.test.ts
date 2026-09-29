import { afterEach, describe, expect, it, vi } from 'vitest'
import { readOnboardingFlags, updateOnboardingFlags } from './onboardingStorage'

describe('onboardingStorage', () => {
  afterEach(() => {
    localStorage.clear()
    vi.restoreAllMocks()
  })

  it('defaults every flag to false', () => {
    expect(readOnboardingFlags()).toEqual({
      checklistDismissed: false,
      testEmailSent: false,
      reviewPromptDismissed: false
    })
  })

  it('persists a patch and keeps the other flags', () => {
    updateOnboardingFlags({ testEmailSent: true })
    updateOnboardingFlags({ checklistDismissed: true })

    expect(readOnboardingFlags()).toEqual({
      checklistDismissed: true,
      testEmailSent: true,
      reviewPromptDismissed: false
    })
  })

  it('ignores corrupt or non-boolean stored values', () => {
    localStorage.setItem('bit_smtp_onboarding', '{"testEmailSent":"yes"')
    expect(readOnboardingFlags().testEmailSent).toBe(false)

    localStorage.setItem('bit_smtp_onboarding', '{"testEmailSent":"yes"}')
    expect(readOnboardingFlags().testEmailSent).toBe(false)
  })

  it('still returns the merged flags when storage throws', () => {
    vi.spyOn(Storage.prototype, 'setItem').mockImplementation(() => {
      throw new Error('blocked')
    })

    expect(updateOnboardingFlags({ reviewPromptDismissed: true }).reviewPromptDismissed).toBe(true)
  })
})
