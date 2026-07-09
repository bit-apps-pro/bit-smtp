import { type FieldMeta } from '@pages/Connections/types'
import MaskedPasswordField from './MaskedPasswordField'
import NumberField from './NumberField'
import SelectField from './SelectField'
import SwitchField from './SwitchField'
import TextField from './TextField'

export default function FieldRenderer({ field }: { field: FieldMeta }) {
  switch (field.type) {
    case 'number':
      return <NumberField field={field} />
    case 'select':
      return <SelectField field={field} />
    case 'switch':
      return <SwitchField field={field} />
    case 'password':
      return <MaskedPasswordField field={field} />
    case 'text':
    case 'email':
    default:
      return <TextField field={field} />
  }
}
