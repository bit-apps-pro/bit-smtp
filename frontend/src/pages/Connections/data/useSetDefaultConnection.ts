import request from '@common/helpers/request'
import { type MailSettings } from '@pages/Connections/types'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { MAIL_SETTINGS_QUERY_KEY } from './useMailSettings'

export default function useSetDefaultConnection() {
  const queryClient = useQueryClient()

  return useMutation({
    // mail/settings/save replaces the whole settings array, so the cached connections must
    // ride along with the new default id or the backend sanitizer drops them.
    mutationFn: (defaultConnectionId: string) => {
      const settings = queryClient.getQueryData<MailSettings>(MAIL_SETTINGS_QUERY_KEY)
      return request({
        action: 'mail/settings/save',
        data: { ...settings, default_connection_id: defaultConnectionId }
      })
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: MAIL_SETTINGS_QUERY_KEY })
    }
  })
}
