import { type FieldMeta } from '@pages/Connections/types'
import { Input } from 'antd'
import FieldItem from './FieldItem'

export default function TextField({ field }: { field: FieldMeta }) {
  return (
    <FieldItem field={field}>
      <Input
        type={field.type === 'email' ? 'email' : 'text'}
        placeholder={field.placeholder}
        aria-label={field.label}
      />
    </FieldItem>
  )
}
