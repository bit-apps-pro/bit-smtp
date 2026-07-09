import { type FieldMeta } from '@pages/Connections/types'
import { Form, Switch } from 'antd'

export default function SwitchField({ field }: { field: FieldMeta }) {
  return (
    <Form.Item label={field.label} name={field.key} valuePropName="checked">
      <Switch aria-label={field.label} />
    </Form.Item>
  )
}
