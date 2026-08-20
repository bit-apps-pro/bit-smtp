import request from '@common/helpers/request'
import { useMutation } from '@tanstack/react-query'

export interface OAuthAuthorizePayload {
  connectionId: string
  provider: string
}

export default function useOAuthAuthorize() {
  return useMutation({
    mutationFn: async ({ connectionId, provider }: OAuthAuthorizePayload): Promise<string> => {
      const response = await request<{ url: string }>({
        action: 'mail/oauth/authorize',
        method: 'GET',
        queryParam: { connection_id: connectionId, provider }
      })

      return response.data.url
    }
  })
}
