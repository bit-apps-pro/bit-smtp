import { FAILURE_CLASSES } from '@common/glossary'

/** Human labels for the classifier's failure_class verdict, derived from the shared glossary. */
// eslint-disable-next-line import/prefer-default-export -- a shared named map, imported by name across views
export const FAILURE_CLASS_LABELS: Record<string, string> = Object.fromEntries(
  Object.entries(FAILURE_CLASSES).map(([key, entry]) => [key, entry.label])
)
