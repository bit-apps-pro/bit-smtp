import { type FieldDependency } from '@pages/Connections/types'

/** Whether a watched value satisfies a field condition: by truthiness for boolean targets, strictly otherwise (mirrors RequiredFieldsValidator). */
export default function conditionHolds(actual: unknown, condition: FieldDependency): boolean {
  return typeof condition.value === 'boolean'
    ? Boolean(actual) === condition.value
    : actual === condition.value
}
