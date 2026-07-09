import request from '@common/helpers/request'
import { type ProviderMeta } from '@pages/Connections/types'
import { useQuery } from '@tanstack/react-query'

export const MAIL_PROVIDERS_QUERY_KEY = ['mail-providers']

export default function useProviders() {
  return useQuery({
    queryKey: MAIL_PROVIDERS_QUERY_KEY,
    queryFn: async () => {
      const response = await request<{ providers: ProviderMeta[] }>({
        action: 'mail/providers',
        method: 'GET'
      })
      return response.data.providers
    }
  })
}
