import request from '@common/helpers/request'
import { type MailSettings } from '@pages/Connections/types'
import { useQueryClient } from '@tanstack/react-query'
import { MAIL_SETTINGS_QUERY_KEY } from './useMailSettings'
import useMailSettingsMutation from './useMailSettingsMutation'

/**
 * Flip a single connection's `enabled` flag. Rides the full cached settings blob through
 * mail/settings/save (like useSetDefaultConnection) so the sanitizer keeps every other connection.
 */
export default function useToggleConnectionEnabled() {
  const queryClient = useQueryClient()

  return useMailSettingsMutation(({ id, enabled }: { id: string; enabled: boolean }) => {
    const settings = queryClient.getQueryData<MailSettings>(MAIL_SETTINGS_QUERY_KEY)
    if (!settings) {
      // Never POST without the cached connections — the sanitizer would wipe them all.
      return Promise.reject(new Error('Mail settings not loaded'))
    }
    return request({
      action: 'mail/settings/save',
      data: {
        ...settings,
        connections: settings.connections.map(connection =>
          connection.id === id ? { ...connection, enabled } : connection
        )
      }
    })
  })
}
