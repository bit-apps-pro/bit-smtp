import { __ } from '@common/helpers/i18nwrap'
import { type FieldMeta } from '@pages/Connections/types'
import { Form, InputNumber } from 'antd'

export default function NumberField({ field }: { field: FieldMeta }) {
  return (
    <Form.Item
      label={field.label}
      name={field.key}
      rules={field.required ? [{ required: true, message: __('This field is required') }] : undefined}
    >
      <InputNumber placeholder={field.placeholder} aria-label={field.label} style={{ width: '100%' }} />
    </Form.Item>
  )
}
