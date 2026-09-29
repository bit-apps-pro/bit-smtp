import { type FieldMeta } from '@pages/Connections/types'
import { InputNumber } from 'antd'
import FieldItem from './FieldItem'

const NUMBER_INPUT_WIDTH = 160

/** Renders a numeric provider setting (e.g. a port) at an intrinsic, not full, width. */
export default function NumberField({ field }: { field: FieldMeta }) {
  return (
    <FieldItem field={field}>
      <InputNumber
        placeholder={field.placeholder}
        aria-label={field.label}
        style={{ width: NUMBER_INPUT_WIDTH }}
      />
    </FieldItem>
  )
}
