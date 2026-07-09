import { __ } from '@common/helpers/i18nwrap'
import DebugOutput from '@components/DebugOutput/DebugOutput'
import notify from '@components/Toaster/Toaster'
import { type Connection } from '@pages/Connections/types'
import { Button } from 'antd'
import useTestConnection from './data/useTestConnection'

interface ConnectionTestButtonProps {
  getConnection: () => Connection
  to: string
}

export default function ConnectionTestButton({ getConnection, to }: ConnectionTestButtonProps) {
  const { mutate, isPending, data } = useTestConnection()

  const handleTest = () => {
    mutate(
      { connection: getConnection(), to },
      {
        onSuccess: result => {
          if (!result.ok) {
            notify.error(result.error || __('Connection test failed'))
          }
        },
        onError: () => {
          notify.error(__('Connection test failed'))
        }
      }
    )
  }

  return (
    <>
      <Button type="primary" onClick={handleTest} loading={isPending}>
        {__('Test Connection')}
      </Button>
      {data?.debug?.length ? <DebugOutput log={data.debug} /> : null}
    </>
  )
}
