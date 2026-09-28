import { type MailSettings } from '@pages/Connections/types'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { MAIL_SETTINGS_QUERY_KEY } from './useMailSettings'
import useUpdateSettings from './useUpdateSettings'

/** Flip one connection's `enabled` flag by delegating to useUpdateSettings with a recomputed connections list, so the sanitizer keeps every other connection. */
export default function useToggleConnectionEnabled() {
  const queryClient = useQueryClient()
  const updateSettings = useUpdateSettings()

  return useMutation({
    mutationFn: ({ id, enabled }: { id: string; enabled: boolean }) => {
      const settings = queryClient.getQueryData<MailSettings>(MAIL_SETTINGS_QUERY_KEY)
      if (!settings) {
        return updateSettings.mutateAsync({})
      }
      return updateSettings.mutateAsync({
        connections: settings.connections.map(connection =>
          connection.id === id ? { ...connection, enabled } : connection
        )
      })
    }
  })
}
