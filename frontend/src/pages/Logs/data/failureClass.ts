import { __ } from '@common/helpers/i18nwrap'

/** Human labels for the classifier's failure_class verdict, shared across the log and retry-queue views. */
// eslint-disable-next-line import/prefer-default-export -- a shared named map, imported by name across views
export const FAILURE_CLASS_LABELS: Record<string, string> = {
  transient: __('Transient'),
  rate_limited: __('Rate limited'),
  auth: __('Auth error'),
  invalid_recipient: __('Invalid recipient'),
  permanent: __('Permanent')
}
