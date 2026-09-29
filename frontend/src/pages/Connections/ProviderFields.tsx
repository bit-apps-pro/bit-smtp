import { type FieldDependency, type FieldMeta } from '@pages/Connections/types'
import { Form } from 'antd'
import FieldRenderer from './fields/FieldRenderer'
import conditionHolds from './fields/fieldCondition'

/** Renders a field only while the field it depends on satisfies its condition. */
function DependentField({ field, dependsOn }: { field: FieldMeta; dependsOn: FieldDependency }) {
  const watchedValue = Form.useWatch(dependsOn.field)

  if (!conditionHolds(watchedValue, dependsOn)) {
    return null
  }

  return <FieldRenderer field={field} />
}

/** Renders a provider's metadata-driven fields in order, honouring `dependsOn` visibility. */
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
