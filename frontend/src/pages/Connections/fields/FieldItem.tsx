import { type ReactNode } from 'react'
import { __ } from '@common/helpers/i18nwrap'
import { type FieldMeta } from '@pages/Connections/types'
import { Form } from 'antd'

export default function FieldItem({ field, children }: { field: FieldMeta; children: ReactNode }) {
  return (
    <Form.Item
      label={field.label}
      name={field.key}
      rules={field.required ? [{ required: true, message: __('This field is required') }] : undefined}
    >
      {children}
    </Form.Item>
  )
}
