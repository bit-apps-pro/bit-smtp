import request from '@common/helpers/request'
import useMailSettingsMutation from './useMailSettingsMutation'

/** Clear a connection's stored OAuth tokens without deleting the connection; refetches mail settings. */
export default function useOAuthDisconnect() {
  return useMailSettingsMutation((connectionId: string) =>
    request({ action: 'mail/oauth/disconnect', data: { connection_id: connectionId } })
  )
}
