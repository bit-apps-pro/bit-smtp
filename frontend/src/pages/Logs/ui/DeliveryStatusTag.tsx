import { type DeliveryStatusKey, deliveryStatusEntry } from '@common/glossary'
import { Tag, Tooltip } from 'antd'

/** Tag color per delivery status; anything unlisted renders in the neutral default. */
const STATUS_COLOR: Partial<Record<DeliveryStatusKey, string>> = {
  delivered: 'green',
  bounced: 'red',
  spam: 'red',
  blocked: 'red',
  deferred: 'gold',
  pending: 'gold'
}

/** Filterable stored statuses; "pending" is a NULL-rendered UI state and "not_tracked" is never stored. */
const FILTERABLE_DELIVERY_STATUSES: ReadonlyArray<DeliveryStatusKey> = [
  'delivered',
  'deferred',
  'bounced',
  'blocked',
  'spam',
  'accepted'
]

/** Glossary label for a delivery_status value, shared by this Tag and the Logs filter select/chip. */
export function deliveryStatusLabel(status: string): string {
  return deliveryStatusEntry(status).label
}

/** Select options for the Logs page's Delivery status filter, labelled from the shared glossary. */
export const DELIVERY_STATUS_OPTIONS = FILTERABLE_DELIVERY_STATUSES.map(value => ({
  value,
  label: deliveryStatusLabel(value)
}))

/** Colored delivery-status tag with the glossary hint as tooltip; a missing status reads as "Awaiting report". */
export default function DeliveryStatusTag({ status }: { status?: string | null }) {
  const key = status || 'pending'
  const entry = deliveryStatusEntry(key)
  const color = STATUS_COLOR[key as DeliveryStatusKey]

  return (
    <Tooltip title={entry.hint}>
      <Tag color={color}>{entry.label}</Tag>
    </Tooltip>
  )
}
