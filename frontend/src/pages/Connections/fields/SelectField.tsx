import { __ } from '@common/helpers/i18nwrap'
import { type FieldMeta } from '@pages/Connections/types'
import { Form, Select } from 'antd'

export default function SelectField({ field }: { field: FieldMeta }) {
  return (
    <Form.Item
      label={field.label}
      name={field.key}
      rules={field.required ? [{ required: true, message: __('This field is required') }] : undefined}
    >
      <Select placeholder={field.placeholder} options={field.options} />
    </Form.Item>
  )
}
