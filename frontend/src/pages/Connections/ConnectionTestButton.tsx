import { __ } from '@common/helpers/i18nwrap'
import DebugOutput from '@components/DebugOutput/DebugOutput'
import notify from '@components/Toaster/Toaster'
import { type Connection } from '@pages/Connections/types'
import { Alert, Button, Space } from 'antd'
import useTestConnection, { type ConnectionTestResult } from './data/useTestConnection'

interface ConnectionTestButtonProps {
  getConnection: () => Connection
  to: string
  onResult?: (result: ConnectionTestResult | undefined) => void
}

/** Maps a test-send delivery outcome to an Alert type + message. */
function connectionTestFeedback(result: ConnectionTestResult): {
  type: 'success' | 'warning' | 'error'
  message: string
} {
  const state = result.delivery?.state
  const detail = result.delivery?.detail

  switch (state) {
    case 'delivered':
      return { type: 'success', message: __('Delivered') }
    case 'accepted':
      return { type: 'success', message: __('Accepted by provider — delivery pending') }
    case 'deferred':
      return { type: 'warning', message: detail || __('Delivery deferred by provider') }
    case 'blocked':
    case 'bounced':
    case 'spam':
      return { type: 'error', message: detail || __('Message not delivered') }
    default:
      return result.ok
        ? { type: 'success', message: __('Connection test successful') }
        : { type: 'error', message: result.error || __('Connection test failed') }
  }
}

/** Shows a toast reflecting the delivery outcome of a test send. */
function notifyDeliveryOutcome(result: ConnectionTestResult) {
  const feedback = connectionTestFeedback(result)
  notify[feedback.type](feedback.message)
}

/** Sticky-bar action: triggers a test send and hands the result to the parent to render. */
export default function ConnectionTestButton({
  getConnection,
  to,
  onResult
}: ConnectionTestButtonProps) {
  const { mutate, isPending } = useTestConnection()

  const handleTest = () => {
    mutate(
      { connection: getConnection(), to },
      {
        // Bubble the mutation result up so the caller can render it outside the sticky bar (#32).
        onSuccess: result => {
          notifyDeliveryOutcome(result)
          onResult?.(result)
        },
        onError: () => {
          notify.error(__('Connection test failed'))
        }
      }
    )
  }

  return (
    <Button type="primary" onClick={handleTest} loading={isPending}>
      {__('Test Connection')}
    </Button>
  )
}

/** Renders the last test result (Alert + debug log) — kept out of the sticky action bar. */
export function ConnectionTestOutcome({ result }: { result?: ConnectionTestResult }) {
  const debugLog: unknown = result?.debug
  const log = Array.isArray(debugLog) ? debugLog : []
  const feedback = result ? connectionTestFeedback(result) : null

  if (!feedback) {
    return null
  }

  return (
    <Space direction="vertical" size="small" style={{ width: '100%' }}>
      <Alert type={feedback.type} message={feedback.message} showIcon />
      {log.length > 0 ? <DebugOutput log={log as string[]} /> : null}
    </Space>
  )
}
