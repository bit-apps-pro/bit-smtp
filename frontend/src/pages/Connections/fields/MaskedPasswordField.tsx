import { useState } from 'react'
import { type FieldMeta, MASK_SENTINEL } from '@pages/Connections/types'
import { Form, Input } from 'antd'
import FieldItem from './FieldItem'

// Form seeds this field with the MASK_SENTINEL; leaving it untouched re-submits the
// sentinel so the backend preserves the stored secret. While masked, the eye toggle is
// hidden (nothing real behind it to reveal). Focusing clears the sentinel so the user can
// type a fresh secret; blurring an untouched field restores it, so an accidental
// focus/blur never wipes the stored credential.
export default function MaskedPasswordField({ field }: { field: FieldMeta }) {
  const form = Form.useFormInstance()
  const [hadStoredSecret] = useState(() => form.getFieldValue(field.key) === MASK_SENTINEL)
  const isMasked = Form.useWatch(field.key) === MASK_SENTINEL

  /** Clears the masked sentinel on focus so the user can type a new secret. */
  const clearOnFocus = () => {
    if (isMasked) {
      form.setFieldValue(field.key, '')
    }
  }

  /** Restores the mask sentinel on blur if the field was left untouched. */
  const restoreOnBlur = () => {
    if (hadStoredSecret && form.getFieldValue(field.key) === '') {
      form.setFieldValue(field.key, MASK_SENTINEL)
    }
  }

  return (
    <FieldItem field={field}>
      <Input.Password
        aria-label={field.label}
        placeholder={field.placeholder}
        autoComplete="new-password"
        visibilityToggle={!isMasked}
        onFocus={clearOnFocus}
        onBlur={restoreOnBlur}
      />
    </FieldItem>
  )
}
