import request from '@common/helpers/request'
import useMailSettingsMutation from './useMailSettingsMutation'

export default function useDeleteConnection() {
  return useMailSettingsMutation((id: string) =>
    request({ action: 'mail/connections/delete', data: { id } })
  )
}
