import { useEffect, useId, useRef, useState } from 'react'
import { __, sprintf } from '@common/helpers/i18nwrap'
import notify from '@components/Toaster/Toaster'
import { type Connection } from '@pages/Connections/types'
import { useQueryClient } from '@tanstack/react-query'
import { Alert, Button, Flex, Form, Popconfirm, Space, Tag, Typography } from 'antd'
import useDeleteConnection from './data/useDeleteConnection'
import useMailSettings, { MAIL_SETTINGS_QUERY_KEY } from './data/useMailSettings'
import useOAuthAuthorize from './data/useOAuthAuthorize'
import useOAuthDisconnect from './data/useOAuthDisconnect'
import { oauthFailureMessage, signInLabels } from './oauthMessages'

const { Text } = Typography

const OAUTH_MESSAGE_TYPE = 'bit-smtp-oauth'
const POPUP_NAME = 'bitsmtp_oauth'
const POPUP_FEATURES = 'width=600,height=700'
const ACCOUNT_SETTING = 'oauth_account'

interface OAuthCallbackMessage {
  type: string
  status: 'success' | 'error'
  connectionId?: string
  provider?: string
  reason?: string
}

/** Type guard for a same-origin OAuth popup postMessage payload. */
function isOAuthCallbackMessage(data: unknown): data is OAuthCallbackMessage {
  return (
    typeof data === 'object' && data !== null && (data as { type?: unknown }).type === OAUTH_MESSAGE_TYPE
  )
}

/** Only real https consent pages may be loaded into the popup. */
function isHttpsUrl(url: string): boolean {
  try {
    return new URL(url).protocol === 'https:'
  } catch {
    return false
  }
}

/** Whether a stored connection holds an OAuth token, i.e. sign-in finished. */
function hasOAuthToken(connection: Connection): boolean {
  return Boolean(
    connection.credentials?.refresh_token?.value || connection.credentials?.access_token?.value
  )
}

/** Live sign-in state from the cached mail settings, falling back to the editor's snapshot before the first save. */
function useStoredSignIn(connectionId: string, connectedSnapshot: boolean) {
  const { data: settings } = useMailSettings()
  const stored = connectionId ? settings?.connections?.find(item => item.id === connectionId) : undefined
  const account = stored?.settings?.[ACCOUNT_SETTING]

  return {
    connected: stored ? hasOAuthToken(stored) : connectedSnapshot,
    account: typeof account === 'string' ? account : ''
  }
}

/** Subscribes to same-origin OAuth popup callbacks addressed to this connection. */
function useOAuthCallback(connectionId: string, onMessage: (message: OAuthCallbackMessage) => void) {
  const handlerRef = useRef(onMessage)
  handlerRef.current = onMessage

  useEffect(() => {
    /** Forwards a trusted callback message for this connection to the latest handler. */
    function handleMessage(event: MessageEvent) {
      if (event.origin !== window.location.origin || !isOAuthCallbackMessage(event.data)) {
        return
      }
      if (event.data.connectionId && event.data.connectionId !== connectionId) {
        return
      }
      handlerRef.current(event.data)
    }

    window.addEventListener('message', handleMessage)
    return () => window.removeEventListener('message', handleMessage)
  }, [connectionId])
}

/**
 * Sign-in button for OAuth providers: validates and saves the form, opens the consent popup inside the click
 * (so popup blockers allow it), reports plain-language failures, and shows which account is connected.
 */
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
  const form = Form.useFormInstance()
  const queryClient = useQueryClient()
  const { mutateAsync: authorize, isPending: isAuthorizing } = useOAuthAuthorize()
  const { mutateAsync: disconnect, isPending: isDisconnecting } = useOAuthDisconnect()
  const { mutateAsync: deleteConnection, isPending: isDiscarding } = useDeleteConnection()
  const signIn = useStoredSignIn(connectionId, connected)
  const labels = signInLabels(provider)
  const hintId = useId()
  const [isSaving, setIsSaving] = useState(false)
  const [blockedUrl, setBlockedUrl] = useState<string>()
  const [failure, setFailure] = useState<string>()
  const [createdHere, setCreatedHere] = useState(false)

  useOAuthCallback(connectionId, message => {
    queryClient.invalidateQueries({ queryKey: MAIL_SETTINGS_QUERY_KEY })
    if (message.status === 'success') {
      setFailure(undefined)
      setCreatedHere(false)
      notify.success(__('Account connected'))
      return
    }
    setFailure(oauthFailureMessage(message.reason, labels.brand))
  })

  /** Validates, saves and fetches the consent URL; resolves '' when any step fails (the user is told why). */
  const prepareSignIn = async (): Promise<string> => {
    try {
      await form?.validateFields()
    } catch {
      notify.error(__('Fill in the highlighted fields, then sign in again.'))
      return ''
    }

    setIsSaving(true)
    let id: string
    try {
      id = await persistConnection()
    } finally {
      setIsSaving(false)
    }
    if (!id) {
      return ''
    }
    if (!connectionId) {
      setCreatedHere(true)
    }

    try {
      const url = await authorize({ connectionId: id, provider })
      return isHttpsUrl(url) ? url : ''
    } catch (error) {
      notify.error(
        error instanceof Error ? error.message : __("Couldn't start sign-in. Please try again.")
      )
      return ''
    }
  }

  /** Opens the popup synchronously inside the click, then points it at the consent page once it is ready. */
  const handleSignIn = async () => {
    const popup = window.open('', POPUP_NAME, POPUP_FEATURES)
    setBlockedUrl(undefined)
    setFailure(undefined)

    const url = await prepareSignIn()
    if (!url) {
      popup?.close()
      return
    }
    if (popup && !popup.closed) {
      popup.location.href = url
    } else {
      setBlockedUrl(url)
    }
  }

  /** Retry from the "blocked" notice; a direct click, so the browser allows this popup. */
  const openBlockedUrl = () => {
    if (blockedUrl) {
      window.open(blockedUrl, POPUP_NAME, POPUP_FEATURES)
      setBlockedUrl(undefined)
    }
  }

  /** Deletes a connection this button saved when sign-in didn't finish, so no half-set-up connection is left behind. */
  const discardConnection = async () => {
    try {
      await deleteConnection(connectionId)
      setCreatedHere(false)
      setFailure(undefined)
      notify.success(__('Connection removed'))
    } catch {
      notify.error(__("Couldn't remove the connection. Delete it from the Connections list."))
    }
  }

  /** Revokes the stored OAuth grant without deleting the connection; the refetch flips it back to "not connected". */
  const handleDisconnect = async () => {
    try {
      await disconnect(connectionId)
      notify.success(__('Account disconnected'))
    } catch {
      notify.error(__("Couldn't disconnect the account. Please try again."))
    }
  }

  /** "Sign in again" once connected; otherwise say whether clicking also saves a new connection. */
  const buttonLabel = () => {
    if (signIn.connected) {
      return __('Sign in again')
    }
    return connectionId ? labels.signIn : labels.saveAndSignIn
  }

  return (
    <Flex vertical gap="small">
      <Space wrap>
        <Button
          type={signIn.connected ? 'default' : 'primary'}
          onClick={handleSignIn}
          loading={isAuthorizing || isSaving}
          aria-describedby={connectionId ? undefined : hintId}
        >
          {buttonLabel()}
        </Button>
        {signIn.connected ? (
          <>
            <Tag color="success">
              {signIn.account ? sprintf(__('Connected as %s'), signIn.account) : __('Connected')}
            </Tag>
            <Popconfirm
              title={__('Disconnect this account?')}
              description={__("You'll need to sign in again before this connection can send email.")}
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
      {connectionId ? null : (
        <Text id={hintId} type="secondary">
          {__("This saves the connection first. It won't send email until you finish signing in.")}
        </Text>
      )}
      {blockedUrl ? (
        <Alert
          type="warning"
          showIcon
          message={__('Your browser blocked the sign-in window.')}
          action={
            <Button size="small" onClick={openBlockedUrl}>
              {__('Open sign-in page')}
            </Button>
          }
        />
      ) : null}
      {failure && !signIn.connected ? (
        <Alert
          type="error"
          showIcon
          message={failure}
          action={
            createdHere ? (
              <Button size="small" danger loading={isDiscarding} onClick={discardConnection}>
                {__('Remove this connection')}
              </Button>
            ) : undefined
          }
        />
      ) : null}
    </Flex>
  )
}
