import { useState } from 'react'
import { MASK_SENTINEL } from '@pages/Connections/types'
import { Form } from 'antd'

export interface MaskedSecretProps {
  /** False while the value is the mask sentinel, hiding the eye toggle — nothing real sits behind it. */
  visibilityToggle: boolean
  onFocus: () => void
  onBlur: () => void
}

/**
 * Masking behavior for a saved-secret password field, shared by the Connections and Notifications
 * secret inputs: hides the reveal toggle while the value is the mask sentinel, clears the sentinel on
 * focus so a fresh secret can be typed, and restores it on an untouched blur so an accidental
 * focus/blur never wipes the stored credential. Apply the returned props to the `Input.Password`
 * bound to `name` within the surrounding antd Form.
 */
export default function useMaskedSecret(name: string): MaskedSecretProps {
  const form = Form.useFormInstance()
  const [hadStoredSecret] = useState(() => form.getFieldValue(name) === MASK_SENTINEL)
  const isMasked = Form.useWatch(name, form) === MASK_SENTINEL

  return {
    visibilityToggle: !isMasked,
    onFocus: () => {
      if (isMasked) {
        form.setFieldValue(name, '')
      }
    },
    onBlur: () => {
      if (hadStoredSecret && form.getFieldValue(name) === '') {
        form.setFieldValue(name, MASK_SENTINEL)
      }
    }
  }
}
