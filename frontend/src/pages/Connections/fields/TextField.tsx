import { __ } from '@common/helpers/i18nwrap'
import { type FieldMeta } from '@pages/Connections/types'
import { Form, Input } from 'antd'

export default function TextField({ field }: { field: FieldMeta }) {
  return (
    <Form.Item
      label={field.label}
      name={field.key}
      rules={field.required ? [{ required: true, message: __('This field is required') }] : undefined}
    >
      <Input
        type={field.type === 'email' ? 'email' : 'text'}
        placeholder={field.placeholder}
        aria-label={field.label}
      />
    </Form.Item>
  )
}
