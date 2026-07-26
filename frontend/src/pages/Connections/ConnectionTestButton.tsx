import { __ } from '@common/helpers/i18nwrap'
import DebugOutput from '@components/DebugOutput/DebugOutput'
import notify from '@components/Toaster/Toaster'
import { type Connection } from '@pages/Connections/types'
import { Alert, Button, Space } from 'antd'
import useTestConnection, { type ConnectionTestResult } from './data/useTestConnection'

interface ConnectionTestButtonProps {
  getConnection: () => Connection
  to: string
}

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

function notifyDeliveryOutcome(result: ConnectionTestResult) {
  const feedback = connectionTestFeedback(result)
  notify[feedback.type](feedback.message)
}

export default function ConnectionTestButton({ getConnection, to }: ConnectionTestButtonProps) {
  const { mutate, isPending, data } = useTestConnection()

  const handleTest = () => {
    mutate(
      { connection: getConnection(), to },
      {
        onSuccess: notifyDeliveryOutcome,
        onError: () => {
          notify.error(__('Connection test failed'))
        }
      }
    )
  }

  const debugLog: unknown = data?.debug
  const log = Array.isArray(debugLog) ? debugLog : []
  const feedback = data ? connectionTestFeedback(data) : null

  return (
    <Space direction="vertical" size="small">
      <Button type="primary" onClick={handleTest} loading={isPending}>
        {__('Test Connection')}
      </Button>
      {feedback ? <Alert type={feedback.type} message={feedback.message} showIcon /> : null}
      {log.length > 0 ? <DebugOutput log={log as string[]} /> : null}
    </Space>
  )
}
