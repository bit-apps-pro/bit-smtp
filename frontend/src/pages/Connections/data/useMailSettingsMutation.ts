import { type MutationFunction, useMutation, useQueryClient } from '@tanstack/react-query'
import { MAIL_SETTINGS_QUERY_KEY } from './useMailSettings'

export default function useMailSettingsMutation<TData, TVariables>(
  mutationFn: MutationFunction<TData, TVariables>
) {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn,
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: MAIL_SETTINGS_QUERY_KEY })
    }
  })
}
