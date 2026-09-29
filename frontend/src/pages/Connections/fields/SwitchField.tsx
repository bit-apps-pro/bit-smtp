import { type FieldMeta } from '@pages/Connections/types'
import { Form, Switch } from 'antd'
import { getFieldHint } from './FieldItem'

/** Renders a boolean provider setting as a labelled toggle with its guidance. */
export default function SwitchField({ field }: { field: FieldMeta }) {
  return (
    <Form.Item label={field.label} name={field.key} valuePropName="checked" extra={getFieldHint(field)}>
      <Switch aria-label={field.label} />
    </Form.Item>
  )
}
