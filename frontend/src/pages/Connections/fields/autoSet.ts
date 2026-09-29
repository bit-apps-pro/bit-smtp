import { type FieldMeta } from '@pages/Connections/types'

/** Values to write after a user edit: each changed field's `autoSet` target, unless the user already customised it. */
export default function autoSetPatch(
  changed: Record<string, unknown>,
  values: Record<string, unknown>,
  fields: FieldMeta[]
): Record<string, unknown> {
  return Object.fromEntries(
    fields.flatMap(field => {
      if (!field.autoSet || !(field.key in changed)) {
        return []
      }

      const { field: target, values: mapped } = field.autoSet
      const next = mapped[String(changed[field.key])]
      const current = values[target]
      const stillDefault =
        current === undefined ||
        current === null ||
        current === '' ||
        Object.values(mapped).includes(current as never)

      return next !== undefined && stillDefault ? [[target, next]] : []
    })
  )
}
