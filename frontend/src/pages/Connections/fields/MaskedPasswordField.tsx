import { type FieldMeta } from '@pages/Connections/types'
import { Input } from 'antd'
import FieldItem from './FieldItem'
import useMaskedSecret from './useMaskedSecret'

/** A Connections secret field: a masked-secret Input.Password wrapped in its labelled Form.Item. */
export default function MaskedPasswordField({ field }: { field: FieldMeta }) {
  const mask = useMaskedSecret(field.key)

  return (
    <FieldItem field={field}>
      <Input.Password
        aria-label={field.label}
        placeholder={field.placeholder}
        autoComplete="new-password"
        visibilityToggle={mask.visibilityToggle}
        onFocus={mask.onFocus}
        onBlur={mask.onBlur}
      />
    </FieldItem>
  )
}
