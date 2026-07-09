import { type FieldMeta } from '@pages/Connections/types'
import { Input } from 'antd'
import FieldItem from './FieldItem'

// Form seeds this field with the MASK_SENTINEL; leaving it untouched
// re-submits the sentinel so the backend preserves the stored secret.
export default function MaskedPasswordField({ field }: { field: FieldMeta }) {
  return (
    <FieldItem field={field}>
      <Input.Password
        aria-label={field.label}
        placeholder={field.placeholder}
        autoComplete="new-password"
      />
    </FieldItem>
  )
}
