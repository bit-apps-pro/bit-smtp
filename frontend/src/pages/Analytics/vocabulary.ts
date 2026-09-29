import { DELIVERY_STATUSES, type GlossaryEntry, SEND_RESULTS } from '@common/glossary'
import { __ } from '@common/helpers/i18nwrap'
import { glossaryLegendDetails } from '@pages/Logs/ui/GlossaryLegend'

/** Analytics metric names, sharing the Logs Result/Delivery vocabulary from the glossary. */
export const METRICS = {
  total: {
    label: __('Total emails'),
    hint: __('Every email logged in this range, whether it was sent or failed.')
  },
  sent: SEND_RESULTS.sent,
  sentRate: {
    label: __('Sent rate'),
    hint: __('Share of all emails that your mail provider accepted.')
  },
  delivered: DELIVERY_STATUSES.delivered,
  deliveredRate: {
    label: __('Delivered rate'),
    hint: __(
      "Share of tracked emails that reached the recipient's mail server. Only connections that report delivery count."
    )
  },
  failed: SEND_RESULTS.failed
} as const satisfies Record<string, GlossaryEntry>

/** Legend explaining every headline metric on the Analytics dashboard. */
export const METRICS_LEGEND = glossaryLegendDetails(__('What these numbers mean'), [
  METRICS.total,
  METRICS.sentRate,
  METRICS.deliveredRate,
  METRICS.failed
])
