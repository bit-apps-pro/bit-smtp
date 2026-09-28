import { __ } from '@common/helpers/i18nwrap'
import { Tag } from 'antd'

type DeliveryPresentation = { color: string; label: string }

/** Presentation for verified connection with no provider event yet. */
const PENDING: DeliveryPresentation = { color: 'gold', label: __('Pending') }

const PRESENTATION: Record<string, DeliveryPresentation> = {
  delivered: { color: 'green', label: __('Delivered') },
  bounced: { color: 'red', label: __('Bounced') },
  spam: { color: 'red', label: __('Spam') },
  blocked: { color: 'red', label: __('Blocked') },
  deferred: { color: 'gold', label: __('Deferred') },
  accepted: { color: 'default', label: __('Accepted') }
}

/** Sortable delivery statuses; "Pending" (NULL status) is excluded from this list since it's never persisted. */
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

/** Renders a colored delivery-status tag, falling back to the pending presentation when no status is set. */
export default function DeliveryStatusTag({ status }: { status?: string | null }) {
  const presentation = status ? PRESENTATION[status] : PENDING
  if (!presentation) return null
  return <Tag color={presentation.color}>{presentation.label}</Tag>
}
