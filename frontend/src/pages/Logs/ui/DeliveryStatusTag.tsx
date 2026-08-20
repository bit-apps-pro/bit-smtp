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

export default function DeliveryStatusTag({ status }: { status?: string | null }) {
  const presentation = status ? PRESENTATION[status] : PENDING
  if (!presentation) return null
  return <Tag color={presentation.color}>{presentation.label}</Tag>
}
