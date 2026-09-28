import { type MutationFunction, useMutation, useQueryClient } from '@tanstack/react-query'
import { MAIL_SETTINGS_QUERY_KEY } from './useMailSettings'

/** A mail-settings write that refetches the settings after any response, error envelopes (e.g. an alerts_conflict 409) included, so the next save echoes a fresh alerts revision. */
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
