import { type FieldMeta } from '@pages/Connections/types'
import { Form } from 'antd'
import FieldRenderer from './fields/FieldRenderer'

function ProviderField({ field }: { field: FieldMeta }) {
  const dependency = field.dependsOn
  const watchedValue = Form.useWatch(dependency?.field)

  if (dependency && watchedValue !== dependency.value) {
    return null
  }

  return <FieldRenderer field={field} />
}

export default function ProviderFields({ fields }: { fields: FieldMeta[] }) {
  return (
    <>
      {fields.map(field => (
        <ProviderField key={field.key} field={field} />
      ))}
    </>
  )
}
