import { __ } from '@common/helpers/i18nwrap'
import { type FieldMeta } from '@pages/Connections/types'
import { Form, Input } from 'antd'

// Form seeds this field with the '********' sentinel; leaving it untouched
// re-submits the sentinel so the backend preserves the stored secret.
export default function MaskedPasswordField({ field }: { field: FieldMeta }) {
  return (
    <Form.Item
      label={field.label}
      name={field.key}
      rules={field.required ? [{ required: true, message: __('This field is required') }] : undefined}
    >
      <Input.Password
        aria-label={field.label}
        placeholder={field.placeholder}
        autoComplete="new-password"
      />
    </Form.Item>
  )
}
