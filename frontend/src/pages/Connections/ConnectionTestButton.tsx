import { __ } from '@common/helpers/i18nwrap'
import DebugOutput from '@components/DebugOutput/DebugOutput'
import notify from '@components/Toaster/Toaster'
import { type Connection } from '@pages/Connections/types'
import { Button } from 'antd'
import useTestConnection, { type ConnectionTestResult } from './data/useTestConnection'

interface ConnectionTestButtonProps {
  getConnection: () => Connection
  to: string
}

function notifyDeliveryOutcome(result: ConnectionTestResult) {
  const state = result.delivery?.state
  const detail = result.delivery?.detail

  switch (state) {
    case 'delivered':
      notify.success(__('Delivered'))
      break
    case 'accepted':
      notify.success(__('Accepted by provider — delivery pending'))
      break
    case 'deferred':
      notify.warning(detail || __('Delivery deferred by provider'))
      break
    case 'blocked':
    case 'bounced':
    case 'spam':
      notify.error(detail || __('Message not delivered'))
      break
    default:
      if (!result.ok) {
        notify.error(result.error || __('Connection test failed'))
      }
  }
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

  return (
    <>
      <Button type="primary" onClick={handleTest} loading={isPending}>
        {__('Test Connection')}
      </Button>
      {log.length > 0 ? <DebugOutput log={log as string[]} /> : null}
    </>
  )
}
