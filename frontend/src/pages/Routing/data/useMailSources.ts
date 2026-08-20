import request from '@common/helpers/request'
import { type MailSource } from '@pages/Routing/types'
import { useQuery } from '@tanstack/react-query'

export const MAIL_SOURCES_QUERY_KEY = ['mail-sources']

/** Fetches the runtime-detected wp_mail source plugins for the routing "Source plugin" picker. */
export default function useMailSources() {
  return useQuery({
    queryKey: MAIL_SOURCES_QUERY_KEY,
    refetchOnWindowFocus: false,
    queryFn: async () => {
      const response = await request<{ sources: MailSource[] }>({
        action: 'mail/routing/sources',
        method: 'GET'
      })
      return response.data.sources
    }
  })
}
