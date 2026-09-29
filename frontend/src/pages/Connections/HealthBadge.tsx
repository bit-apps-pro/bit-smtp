import { healthStatusEntry } from '@common/glossary'
import { formatTimestamp } from '@common/helpers/datetime'
import { __, sprintf } from '@common/helpers/i18nwrap'
import { HelpPopover } from '@components/FieldHelp'
import { type ConnectionHealth, type ConnectionHealthStatus } from '@pages/Connections/types'
import { Flex, Tag, Typography } from 'antd'

const { Text } = Typography

const TAG_COLOR: Record<ConnectionHealthStatus, string> = {
  healthy: 'success',
  degraded: 'warning',
  unhealthy: 'error',
  unknown: 'default'
}

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

/** The last-error, last-success, last-check and OAuth lines worth showing for this health record. */
function healthDetailLines(health: ConnectionHealth): string[] {
  return [
    health.last_error ? `${__('Last error')}: ${health.last_error}` : null,
    health.last_ok_at ? `${__('Last success')}: ${formatTimestamp(health.last_ok_at)}` : null,
    health.last_probe_at ? `${__('Last checked')}: ${formatTimestamp(health.last_probe_at)}` : null,
    oauthExpiryNote(health.oauth_expires_at)
  ].filter((line): line is string => line !== null)
}

/** Colored health status Tag that, with its ⓘ, is one click/keyboard trigger for a popover explaining it and the latest error. */
export default function HealthBadge({
  health,
  connectionName
}: {
  health: ConnectionHealth
  connectionName?: string
}) {
  const entry = healthStatusEntry(health.status)
  const color = TAG_COLOR[health.status] ?? TAG_COLOR.unknown
  const helpLabel = connectionName
    ? sprintf(__('%s, health details for %s'), entry.label, connectionName)
    : sprintf(__('%s, health details'), entry.label)

  const body = (
    <Flex vertical gap={4}>
      <Text>{entry.hint}</Text>
      {entry.next && <Text type="secondary">{entry.next}</Text>}
      {healthDetailLines(health).map(line => (
        <Text key={line} style={{ overflowWrap: 'anywhere' }}>
          {line}
        </Text>
      ))}
    </Flex>
  )

  return (
    <HelpPopover label={helpLabel} details={{ title: entry.label, body }}>
      <Tag color={color} style={{ marginInlineEnd: 0, cursor: 'pointer' }}>
        {entry.label}
      </Tag>
    </HelpPopover>
  )
}
