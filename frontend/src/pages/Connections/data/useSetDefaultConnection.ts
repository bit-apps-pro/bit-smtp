import { useMutation } from '@tanstack/react-query'
import useUpdateSettings from './useUpdateSettings'

/** Set the default connection id by delegating to useUpdateSettings, which rides it onto the full cached settings so the backend sanitizer keeps every connection. */
export default function useSetDefaultConnection() {
  const updateSettings = useUpdateSettings()

  return useMutation({
    mutationFn: (defaultConnectionId: string) =>
      updateSettings.mutateAsync({ default_connection_id: defaultConnectionId })
  })
}
