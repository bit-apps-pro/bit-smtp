import { type FieldMeta } from '@pages/Connections/types'
import { Select } from 'antd'
import FieldItem from './FieldItem'

const SEARCHABLE_OPTION_COUNT = 8

/** Renders a provider choice; long lists (e.g. SES regions) become type-to-search. */
export default function SelectField({ field }: { field: FieldMeta }) {
  const searchable = field.options.length > SEARCHABLE_OPTION_COUNT

  return (
    <FieldItem field={field}>
      <Select
        placeholder={field.placeholder}
        options={field.options}
        showSearch={searchable}
        optionFilterProp="label"
      />
    </FieldItem>
  )
}
