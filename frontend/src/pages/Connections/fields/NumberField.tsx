import { type FieldMeta } from '@pages/Connections/types'
import { InputNumber } from 'antd'
import FieldItem from './FieldItem'

export default function NumberField({ field }: { field: FieldMeta }) {
  return (
    <FieldItem field={field}>
      <InputNumber placeholder={field.placeholder} aria-label={field.label} style={{ width: '100%' }} />
    </FieldItem>
  )
}
