import { useCallback, useState } from 'react'

const STORAGE_KEY = 'bit_smtp_onboarding'

/** Per-browser first-run progress and dismissals. */
export interface OnboardingFlags {
  checklistDismissed: boolean
  testEmailSent: boolean
  reviewPromptDismissed: boolean
}

const DEFAULT_FLAGS: OnboardingFlags = {
  checklistDismissed: false,
  testEmailSent: false,
  reviewPromptDismissed: false
}

/** Read stored flags, keeping only boolean values; unavailable or corrupt storage yields defaults. */
export function readOnboardingFlags(): OnboardingFlags {
  try {
    const stored: unknown = JSON.parse(localStorage.getItem(STORAGE_KEY) ?? '{}')
    if (typeof stored !== 'object' || stored === null) {
      return { ...DEFAULT_FLAGS }
    }
    const record = stored as Record<string, unknown>
    return (Object.keys(DEFAULT_FLAGS) as (keyof OnboardingFlags)[]).reduce<OnboardingFlags>(
      (flags, key) => ({ ...flags, [key]: record[key] === true }),
      { ...DEFAULT_FLAGS }
    )
  } catch {
    return { ...DEFAULT_FLAGS }
  }
}

/** Save flags, returning false when storage is unavailable (private mode, blocked site data). */
function persistOnboardingFlags(flags: OnboardingFlags): boolean {
  try {
    localStorage.setItem(STORAGE_KEY, JSON.stringify(flags))
    return true
  } catch {
    return false
  }
}

/** Merge a patch into the stored flags and return the result; storage failures keep it in memory only. */
export function updateOnboardingFlags(patch: Partial<OnboardingFlags>): OnboardingFlags {
  const next = { ...readOnboardingFlags(), ...patch }
  persistOnboardingFlags(next)
  return next
}

/** Onboarding flags as React state, with an updater that also persists them. */
export function useOnboardingFlags(): [OnboardingFlags, (patch: Partial<OnboardingFlags>) => void] {
  const [flags, setFlags] = useState(readOnboardingFlags)
  const update = useCallback((patch: Partial<OnboardingFlags>) => {
    setFlags(updateOnboardingFlags(patch))
  }, [])
  return [flags, update]
}
