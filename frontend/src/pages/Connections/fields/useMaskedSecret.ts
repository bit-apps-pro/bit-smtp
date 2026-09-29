import { useEffect, useRef } from 'react'
import { MASK_SENTINEL } from '@pages/Connections/types'
import { Form } from 'antd'
import { type NamePath } from 'antd/es/form/interface'

export interface MaskedSecretProps {
  /** False while the value is the mask sentinel, hiding the eye toggle — nothing real sits behind it. */
  visibilityToggle: boolean
  /** True while a previously saved secret backs the field, even once it is cleared for re-entry. */
  hasStoredSecret: boolean
  onFocus: () => void
  onBlur: () => void
}

/** Masking behavior for a saved-secret password field: clears the sentinel on focus, restores it on an untouched blur. */
export default function useMaskedSecret(name: NamePath): MaskedSecretProps {
  const form = Form.useFormInstance()
  const value = Form.useWatch(name, form)
  const isFocused = useRef(false)
  const hasStoredSecret = useRef(form.getFieldValue(name) === MASK_SENTINEL)
  const isMasked = value === MASK_SENTINEL

  useEffect(() => {
    if (!isFocused.current && value !== undefined) {
      hasStoredSecret.current = value === MASK_SENTINEL
    }
  }, [value])

  return {
    visibilityToggle: !isMasked,
    hasStoredSecret: hasStoredSecret.current || isMasked,
    onFocus: () => {
      isFocused.current = true
      if (isMasked) {
        form.setFieldValue(name, '')
      }
    },
    onBlur: () => {
      isFocused.current = false
      if (hasStoredSecret.current && form.getFieldValue(name) === '') {
        form.setFieldValue(name, MASK_SENTINEL)
      }
    }
  }
}
