import request from '@common/helpers/request'
import { RETRY_QUEUE_QUERY_KEY } from '@pages/Settings/data/useRetryQueue'
import { type Preferences } from '@pages/Settings/types'
import { type QueryClient, useMutation, useQuery, useQueryClient } from '@tanstack/react-query'

export const PREFERENCES_QUERY_KEY = ['preferences']

/** Refresh caches derived from saved preferences: the blob itself and the retry-queue panel (retry_enabled). */
function applySavedPreferences(queryClient: QueryClient, preferences: Preferences): void {
  queryClient.setQueryData(PREFERENCES_QUERY_KEY, preferences)
  // The retry-queue panel keys its "retry is off" warning off the *saved* retry_enabled, so a save
  // that toggles it must refresh that query or the warning contradicts what was just saved.
  queryClient.invalidateQueries({ queryKey: RETRY_QUEUE_QUERY_KEY })
}

/** Load the current preferences blob for the Settings page. */
export default function usePreferences() {
  return useQuery({
    queryKey: PREFERENCES_QUERY_KEY,
    refetchOnWindowFocus: false,
    queryFn: async () => {
      const response = await request<{ preferences: Preferences }>({
        action: 'preferences',
        method: 'GET'
      })
      return response.data.preferences
    }
  })
}

/** Persist a preferences payload and refresh the cached blob with the server's echoed values. */
export function useSavePreferences() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: (values: Partial<Preferences>) =>
      request<{ preferences: Preferences }>({ action: 'preferences/save', data: values }),
    onSuccess: response => {
      if (response.status === 'success') {
        applySavedPreferences(queryClient, response.data.preferences)
      }
    }
  })
}

/** Fetch the current preferences as a download-ready blob (same shape import() re-accepts). */
export function useExportPreferences() {
  return useMutation({
    mutationFn: async () => {
      const response = await request<{ preferences: Preferences }>({
        action: 'preferences/export',
        method: 'GET'
      })
      return response.data.preferences
    }
  })
}

/** Import a preferences payload (export envelope or flat) and refresh the cached blob. */
export function useImportPreferences() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: (payload: Record<string, unknown>) =>
      request<{ preferences: Preferences }>({ action: 'preferences/import', data: payload }),
    onSuccess: response => {
      if (response.status === 'success') {
        applySavedPreferences(queryClient, response.data.preferences)
      }
    }
  })
}
