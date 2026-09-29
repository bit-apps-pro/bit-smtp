import { type FieldMeta } from '@pages/Connections/types'
import { Switch } from 'antd'
import FieldItem from './FieldItem'

/** Renders a boolean provider setting as a labelled toggle with its guidance. */
export default function SwitchField({ field }: { field: FieldMeta }) {
  return (
    <FieldItem field={field} valuePropName="checked">
      <Switch aria-label={field.label} />
    </FieldItem>
  )
}
