import request from '@common/helpers/request'
import { type MailSettings } from '@pages/Connections/types'
import { useQueryClient } from '@tanstack/react-query'
import { MAIL_SETTINGS_QUERY_KEY } from './useMailSettings'
import useMailSettingsMutation from './useMailSettingsMutation'

export default function useSetDefaultConnection() {
  const queryClient = useQueryClient()

  // mail/settings/save replaces the whole settings array, so the cached connections must
  // ride along with the new default id or the backend sanitizer drops them.
  return useMailSettingsMutation((defaultConnectionId: string) => {
    const settings = queryClient.getQueryData<MailSettings>(MAIL_SETTINGS_QUERY_KEY)
    return request({
      action: 'mail/settings/save',
      data: { ...settings, default_connection_id: defaultConnectionId }
    })
  })
}
