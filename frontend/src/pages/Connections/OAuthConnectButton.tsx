import { useEffect, useState } from 'react'
import { __ } from '@common/helpers/i18nwrap'
import notify from '@components/Toaster/Toaster'
import { useQueryClient } from '@tanstack/react-query'
import { Button, Tag } from 'antd'
import { MAIL_SETTINGS_QUERY_KEY } from './data/useMailSettings'
import useOAuthAuthorize from './data/useOAuthAuthorize'

const OAUTH_MESSAGE_TYPE = 'bit-smtp-oauth'
const POPUP_NAME = 'bitsmtp_oauth'
const POPUP_FEATURES = 'width=600,height=700'

interface OAuthCallbackMessage {
  type: string
  status: 'success' | 'error'
  connectionId?: string
  provider?: string
}

function isOAuthCallbackMessage(data: unknown): data is OAuthCallbackMessage {
  return (
    typeof data === 'object' && data !== null && (data as { type?: unknown }).type === OAUTH_MESSAGE_TYPE
  )
}

export default function OAuthConnectButton({
  connectionId,
  provider,
  connected,
  persistConnection
}: {
  connectionId: string
  provider: string
  connected: boolean
  persistConnection: () => Promise<string>
}) {
  const { mutateAsync, isPending } = useOAuthAuthorize()
  const [isSaving, setIsSaving] = useState(false)
  const queryClient = useQueryClient()

  useEffect(() => {
    function handleMessage(event: MessageEvent) {
      if (event.origin !== window.location.origin || !isOAuthCallbackMessage(event.data)) {
        return
      }

      const message = event.data
      if (message.connectionId && message.connectionId !== connectionId) {
        return
      }

      queryClient.invalidateQueries({ queryKey: MAIL_SETTINGS_QUERY_KEY })
      if (message.status === 'success') {
        notify.success(__('OAuth account connected'))
      } else {
        notify.error(__('Failed to connect OAuth account'))
      }
    }

    window.addEventListener('message', handleMessage)
    return () => window.removeEventListener('message', handleMessage)
  }, [connectionId, queryClient])

  // Always persist the current form values before authorizing, so consent runs against the freshly
  // typed OAuth client_id/secret rather than the stored ones (an edit would otherwise authorize with
  // stale credentials). Persisting also mints the id for a brand-new connection. loading blocks a
  // double submit.
  const handleClick = async () => {
    setIsSaving(true)
    let id: string
    try {
      id = await persistConnection()
    } finally {
      setIsSaving(false)
    }
    if (!id) return

    const url = await mutateAsync({ connectionId: id, provider })
    if (url) {
      window.open(url, POPUP_NAME, POPUP_FEATURES)
    }
  }

  return (
    <>
      <Button onClick={handleClick} loading={isPending || isSaving}>
        {connected ? __('Reconnect') : __('Connect')}
      </Button>
      {connected ? (
        <Tag color="success" style={{ marginInlineStart: 8 }}>
          {__('Connected')}
        </Tag>
      ) : null}
    </>
  )
}
