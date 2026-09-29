import { type SendResult, sendResultEntry } from '@common/glossary'
import { __, sprintf } from '@common/helpers/i18nwrap'
import { type LogType } from '@pages/Logs/data/useFetchLogs'
import { Tag, Tooltip } from 'antd'

const RESULT_COLOR: Record<SendResult, string> = {
  sent: 'success',
  failed: 'error',
  failover_recovered: 'warning'
}

/** Number of connections that failed before the one that finally handled this send. */
export function failedAttemptCount(log: Pick<LogType, 'details'>): number {
  const attempts = log.details?.attempts ?? []
  if (attempts.length < 2) return 0
  return attempts.filter(attempt => attempt.status === 'failed').length
}

/** Classifies a log row as sent, failed, or sent only after an earlier connection failed. */
export function sendResultOf(log: Pick<LogType, 'status' | 'details'>): SendResult {
  if (!Number(log.status)) return 'failed'
  return failedAttemptCount(log) > 0 ? 'failover_recovered' : 'sent'
}

/** Tooltip text for a result tag, spelling out the failover count when a backup sent the message. */
function resultTooltip(result: SendResult, failedAttempts: number): string {
  if (result !== 'failover_recovered') return sendResultEntry(result).hint
  return failedAttempts === 1
    ? __('1 earlier connection failed; this one sent it.')
    : sprintf(__('%d earlier connections failed; this one sent it.'), failedAttempts)
}

/** Colored tag for a log row's send result, with the glossary hint as its tooltip. */
export default function SendResultTag({ log }: { log: Pick<LogType, 'status' | 'details'> }) {
  const result = sendResultOf(log)

  return (
    <Tooltip title={resultTooltip(result, failedAttemptCount(log))}>
      <Tag color={RESULT_COLOR[result]}>{sendResultEntry(result).label}</Tag>
    </Tooltip>
  )
}
