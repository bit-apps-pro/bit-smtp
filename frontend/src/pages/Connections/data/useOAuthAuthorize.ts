import { __ } from '@common/helpers/i18nwrap'
import request from '@common/helpers/request'
import { useMutation } from '@tanstack/react-query'

export interface OAuthAuthorizePayload {
  connectionId: string
  provider: string
}

/** Fetches the provider consent URL for a saved connection; rejects with the server's reason when it can't be built. */
export default function useOAuthAuthorize() {
  return useMutation({
    mutationFn: async ({ connectionId, provider }: OAuthAuthorizePayload): Promise<string> => {
      const response = await request<{ url?: string }>({
        action: 'mail/oauth/authorize',
        method: 'GET',
        queryParam: { connection_id: connectionId, provider }
      })

      const url = response.status === 'success' ? response.data?.url : undefined
      if (!url) {
        throw new Error(response.message || __("Couldn't start sign-in. Please try again."))
      }
      return url
    }
  })
}
