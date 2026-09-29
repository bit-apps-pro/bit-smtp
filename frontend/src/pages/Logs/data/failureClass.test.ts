import { FAILURE_CLASSES } from '@common/glossary'
import { describe, expect, it } from 'vitest'
import { FAILURE_CLASS_LABELS } from './failureClass'

describe('FAILURE_CLASS_LABELS', () => {
  it('maps every failure class to its glossary label', () => {
    expect(FAILURE_CLASS_LABELS).toEqual({
      transient: FAILURE_CLASSES.transient.label,
      rate_limited: FAILURE_CLASSES.rate_limited.label,
      auth: FAILURE_CLASSES.auth.label,
      invalid_recipient: FAILURE_CLASSES.invalid_recipient.label,
      permanent: FAILURE_CLASSES.permanent.label
    })
    expect(FAILURE_CLASS_LABELS.auth).toBe('Login or API key rejected')
  })
})
