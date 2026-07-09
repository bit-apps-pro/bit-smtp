import request from '@common/helpers/request'
import { type Connection } from '@pages/Connections/types'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { MAIL_SETTINGS_QUERY_KEY } from './useMailSettings'

export default function useSaveConnection() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: (connection: Connection) =>
      request({ action: 'mail/connections/save', data: connection }),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: MAIL_SETTINGS_QUERY_KEY })
    }
  })
}
