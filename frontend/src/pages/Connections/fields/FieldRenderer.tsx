import { type FieldMeta } from '@pages/Connections/types'
import MaskedPasswordField from './MaskedPasswordField'
import NumberField from './NumberField'
import SelectField from './SelectField'
import SwitchField from './SwitchField'
import TextField from './TextField'

export default function FieldRenderer({ field }: { field: FieldMeta }) {
  if (field.secret) {
    return <MaskedPasswordField field={field} />
  }

  switch (field.type) {
    case 'number':
      return <NumberField field={field} />
    case 'select':
      return <SelectField field={field} />
    case 'switch':
      return <SwitchField field={field} />
    case 'text':
    case 'email':
    default:
      return <TextField field={field} />
  }
}
