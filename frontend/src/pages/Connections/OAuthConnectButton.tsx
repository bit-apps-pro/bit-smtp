import { useEffect } from 'react'
import { __ } from '@common/helpers/i18nwrap'
import notify from '@components/Toaster/Toaster'
import { useQueryClient } from '@tanstack/react-query'
import { Button, Tag, Tooltip } from 'antd'
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
  connected
}: {
  connectionId: string
  provider: string
  connected: boolean
}) {
  const { mutateAsync, isPending } = useOAuthAuthorize()
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

  const handleClick = async () => {
    const url = await mutateAsync({ connectionId, provider })
    if (url) {
      window.open(url, POPUP_NAME, POPUP_FEATURES)
    }
  }

  const button = (
    <Button onClick={handleClick} loading={isPending} disabled={!connectionId}>
      {connected ? __('Reconnect') : __('Connect')}
    </Button>
  )

  return (
    <>
      <Tooltip title={connectionId ? undefined : __('Save the connection before connecting an account')}>
        <span>{button}</span>
      </Tooltip>
      {connected ? (
        <Tag color="success" style={{ marginInlineStart: 8 }}>
          {__('Connected')}
        </Tag>
      ) : null}
    </>
  )
}
