import request from '@common/helpers/request'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { MAIL_SETTINGS_QUERY_KEY } from './useMailSettings'

export default function useDeleteConnection() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: (id: string) => request({ action: 'mail/connections/delete', data: { id } }),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: MAIL_SETTINGS_QUERY_KEY })
    }
  })
}
