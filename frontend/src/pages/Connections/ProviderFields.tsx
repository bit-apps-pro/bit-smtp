import { type FieldDependency, type FieldMeta } from '@pages/Connections/types'
import { Form } from 'antd'
import FieldRenderer from './fields/FieldRenderer'

function DependentField({ field, dependsOn }: { field: FieldMeta; dependsOn: FieldDependency }) {
  const watchedValue = Form.useWatch(dependsOn.field)

  if (watchedValue !== dependsOn.value) {
    return null
  }

  return <FieldRenderer field={field} />
}

export default function ProviderFields({ fields }: { fields: FieldMeta[] }) {
  return (
    <>
      {fields.map(field =>
        field.dependsOn ? (
          <DependentField key={field.key} field={field} dependsOn={field.dependsOn} />
        ) : (
          <FieldRenderer key={field.key} field={field} />
        )
      )}
    </>
  )
}
