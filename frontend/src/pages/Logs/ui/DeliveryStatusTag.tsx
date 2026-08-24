import { __ } from '@common/helpers/i18nwrap'
import { Tag } from 'antd'

type DeliveryPresentation = { color: string; label: string }

// Verified connection, no provider event yet.
const PENDING: DeliveryPresentation = { color: 'gold', label: __('Pending') }

const PRESENTATION: Record<string, DeliveryPresentation> = {
  delivered: { color: 'green', label: __('Delivered') },
  bounced: { color: 'red', label: __('Bounced') },
  spam: { color: 'red', label: __('Spam') },
  blocked: { color: 'red', label: __('Blocked') },
  deferred: { color: 'gold', label: __('Deferred') },
  accepted: { color: 'default', label: __('Accepted') }
}

// Delivery-status values actually written to the column, in display order — these drive the Logs
// filter options. "Pending" is deliberately excluded: it's a UI label for a NULL delivery_status
// (a verified send with no provider event yet), never a stored value, so filtering `= 'pending'`
// would match nothing. It stays in deliveryStatusLabel() only so the Tag can render a NULL row.
const DELIVERY_STATUS_ORDER = [
  'delivered',
  'deferred',
  'bounced',
  'blocked',
  'spam',
  'accepted'
] as const

/** Human label for a delivery_status value, shared by this Tag and the Logs filter select/chip. */
export function deliveryStatusLabel(status: string): string {
  if (status === 'pending') return PENDING.label
  return PRESENTATION[status]?.label ?? status
}

/** Select options for the Logs page's Delivery status filter, derived from the same labels the Tag renders. */
export const DELIVERY_STATUS_OPTIONS = DELIVERY_STATUS_ORDER.map(value => ({
  value,
  label: deliveryStatusLabel(value)
}))

export default function DeliveryStatusTag({ status }: { status?: string | null }) {
  const presentation = status ? PRESENTATION[status] : PENDING
  if (!presentation) return null
  return <Tag color={presentation.color}>{presentation.label}</Tag>
}
