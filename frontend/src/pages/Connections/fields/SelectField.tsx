import { type FieldMeta } from '@pages/Connections/types'
import { Select } from 'antd'
import FieldItem from './FieldItem'

export default function SelectField({ field }: { field: FieldMeta }) {
  return (
    <FieldItem field={field}>
      <Select placeholder={field.placeholder} options={field.options} />
    </FieldItem>
  )
}
