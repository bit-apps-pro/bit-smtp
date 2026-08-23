import request from '@common/helpers/request'
import { type Preferences } from '@pages/Settings/types'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'

export const PREFERENCES_QUERY_KEY = ['preferences']

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
        queryClient.setQueryData(PREFERENCES_QUERY_KEY, response.data.preferences)
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
        queryClient.setQueryData(PREFERENCES_QUERY_KEY, response.data.preferences)
      }
    }
  })
}
