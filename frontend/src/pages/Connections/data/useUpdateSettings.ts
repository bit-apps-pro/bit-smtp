import request from '@common/helpers/request'
import { type MailSettings } from '@pages/Connections/types'
import { useQueryClient } from '@tanstack/react-query'
import toSavePayload from './toSavePayload'
import { MAIL_SETTINGS_QUERY_KEY } from './useMailSettings'
import useMailSettingsMutation from './useMailSettingsMutation'

/** Save a partial settings override merged onto the full cached settings, which must ride along (never posted without them) or the backend sanitizer drops the connections. */
export default function useUpdateSettings() {
  const queryClient = useQueryClient()

  return useMailSettingsMutation((partial: Partial<MailSettings>) => {
    const settings = queryClient.getQueryData<MailSettings>(MAIL_SETTINGS_QUERY_KEY)
    if (!settings) {
      return Promise.reject(new Error('Mail settings not loaded'))
    }
    return request({
      action: 'mail/settings/save',
      data: toSavePayload({ ...settings, ...partial })
    })
  })
}
