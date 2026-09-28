import { useEffect, useState } from 'react'
import { __ } from '@common/helpers/i18nwrap'
import notify from '@components/Toaster/Toaster'
import { useQueryClient } from '@tanstack/react-query'
import { Button, Popconfirm, Space, Tag } from 'antd'
import { MAIL_SETTINGS_QUERY_KEY } from './data/useMailSettings'
import useOAuthAuthorize from './data/useOAuthAuthorize'
import useOAuthDisconnect from './data/useOAuthDisconnect'

const OAUTH_MESSAGE_TYPE = 'bit-smtp-oauth'
const POPUP_NAME = 'bitsmtp_oauth'
const POPUP_FEATURES = 'width=600,height=700'

interface OAuthCallbackMessage {
  type: string
  status: 'success' | 'error'
  connectionId?: string
  provider?: string
}

/** Type guard for a same-origin OAuth popup postMessage payload. */
function isOAuthCallbackMessage(data: unknown): data is OAuthCallbackMessage {
  return (
    typeof data === 'object' && data !== null && (data as { type?: unknown }).type === OAUTH_MESSAGE_TYPE
  )
}

/** Connect/Reconnect button that opens an OAuth consent popup and listens for its postMessage callback. */
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
  const { mutateAsync: disconnect, isPending: isDisconnecting } = useOAuthDisconnect()
  const [isSaving, setIsSaving] = useState(false)
  const queryClient = useQueryClient()

  useEffect(() => {
    /** Applies a same-origin OAuth callback message: invalidates cached mail settings and toasts the outcome. */
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

  /** Persists the form (so consent reads fresh, not stale, OAuth credentials; also mints a new connection's id), then opens the consent popup. */
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

  /** Revokes the stored OAuth grant without deleting the connection; the refetch flips `connected` back to false. */
  const handleDisconnect = async () => {
    try {
      await disconnect(connectionId)
      notify.success(__('OAuth account disconnected'))
    } catch {
      notify.error(__('Failed to disconnect OAuth account'))
    }
  }

  return (
    <Space wrap>
      <Button onClick={handleClick} loading={isPending || isSaving}>
        {connected ? __('Reconnect') : __('Connect')}
      </Button>
      {connected ? (
        <>
          <Tag color="success">{__('Connected')}</Tag>
          <Popconfirm
            title={__('Disconnect this account?')}
            description={__(
              'Stored tokens are cleared; the connection and its client credentials are kept.'
            )}
            okText={__('Disconnect')}
            okButtonProps={{ danger: true }}
            cancelText={__('Cancel')}
            onConfirm={handleDisconnect}
          >
            <Button danger loading={isDisconnecting}>
              {__('Disconnect')}
            </Button>
          </Popconfirm>
        </>
      ) : null}
    </Space>
  )
}
