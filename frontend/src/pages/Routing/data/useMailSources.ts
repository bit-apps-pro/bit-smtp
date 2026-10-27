import request from '@common/helpers/request'
import { type MailSource, type MailSourceCatalog } from '@pages/Routing/types'
import { useQuery } from '@tanstack/react-query'

export const MAIL_SOURCES_QUERY_KEY = ['mail-sources']

/** Fetches seen-sending and other installed plugins for the "Source plugin" picker and log filter. */
export default function useMailSources() {
  return useQuery({
    queryKey: MAIL_SOURCES_QUERY_KEY,
    refetchOnWindowFocus: false,
    queryFn: async (): Promise<MailSourceCatalog> => {
      const response = await request<{ sources: MailSource[]; installed?: MailSource[] }>({
        action: 'mail/routing/sources',
        method: 'GET'
      })
      return { seen: response.data.sources, installed: response.data.installed ?? [] }
    }
  })
}
