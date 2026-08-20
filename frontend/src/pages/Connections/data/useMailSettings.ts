import request from '@common/helpers/request'
import { type MailSettings } from '@pages/Connections/types'
import { useQuery } from '@tanstack/react-query'

export const MAIL_SETTINGS_QUERY_KEY = ['mail-settings']

export default function useMailSettings() {
  return useQuery({
    queryKey: MAIL_SETTINGS_QUERY_KEY,
    refetchOnWindowFocus: false,
    queryFn: async () => {
      const response = await request<{ settings: MailSettings }>({
        action: 'mail/settings',
        method: 'GET'
      })
      return response.data.settings
    }
  })
}
