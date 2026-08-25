import { formatTimestamp } from '@common/helpers/datetime'
import { __ } from '@common/helpers/i18nwrap'
import { type ConnectionHealth, type ConnectionHealthStatus } from '@pages/Connections/types'
import { Flex, Tag, Tooltip } from 'antd'

type StatusPresentation = { color: string; label: string }

const PRESENTATION: Record<ConnectionHealthStatus, StatusPresentation> = {
  healthy: { color: 'success', label: __('Healthy') },
  degraded: { color: 'warning', label: __('Degraded') },
  unhealthy: { color: 'error', label: __('Unhealthy') },
  unknown: { color: 'default', label: __('Unknown') }
}

// Warn once an OAuth access token is inside this window of expiry (or already past it).
// Keep in sync with HealthNotifier::OAUTH_WARNING_WINDOW_SECONDS on the backend.
const OAUTH_EXPIRY_WARNING_SECONDS = 72 * 60 * 60

/** A note when an OAuth access token is expired or expiring soon; null while it is safely valid. */
function oauthExpiryNote(expiresAt: number | null): string | null {
  if (!expiresAt) return null
  const secondsLeft = expiresAt - Date.now() / 1000
  if (secondsLeft <= 0) {
    return __('OAuth token has expired — reconnect this connection.')
  }
  if (secondsLeft <= OAUTH_EXPIRY_WARNING_SECONDS) {
    return `${__('OAuth token expires soon')}: ${new Date(expiresAt * 1000).toLocaleString()}`
  }
  return null
}

/** Colored status Tag for a connection's health, tooltipped with the latest probe/error detail. */
export default function HealthBadge({ health }: { health: ConnectionHealth }) {
  const presentation = PRESENTATION[health.status] ?? PRESENTATION.unknown
  const oauthNote = oauthExpiryNote(health.oauth_expires_at)

  const lines = [
    health.last_error ? `${__('Last error')}: ${health.last_error}` : null,
    health.last_ok_at ? `${__('Last success')}: ${formatTimestamp(health.last_ok_at)}` : null,
    health.last_probe_at ? `${__('Last checked')}: ${formatTimestamp(health.last_probe_at)}` : null,
    oauthNote
  ].filter((line): line is string => line !== null)

  if (lines.length === 0) {
    lines.push(__('No health checks yet.'))
  }

  const tooltip = (
    <Flex vertical gap={2}>
      {lines.map(line => (
        <span key={line}>{line}</span>
      ))}
    </Flex>
  )

  return (
    <Tooltip title={tooltip}>
      <Tag color={presentation.color}>{presentation.label}</Tag>
    </Tooltip>
  )
}
