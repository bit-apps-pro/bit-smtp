import request from '@common/helpers/request'
import { type Connection } from '@pages/Connections/types'
import useMailSettingsMutation from './useMailSettingsMutation'

export default function useSaveConnection() {
  return useMailSettingsMutation((connection: Connection) =>
    request({ action: 'mail/connections/save', data: connection })
  )
}
