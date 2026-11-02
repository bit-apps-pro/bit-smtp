import { useEffect, useId, useRef, useState } from 'react'
import { __, sprintf } from '@common/helpers/i18nwrap'
import notify from '@components/Toaster/Toaster'
import { useQueryClient } from '@tanstack/react-query'
import { Alert, Button, Flex, Form, Popconfirm, Space, Tag, Typography } from 'antd'
import useDeleteConnection from './data/useDeleteConnection'
import useMailSettings, { MAIL_SETTINGS_QUERY_KEY } from './data/useMailSettings'
import useOAuthAuthorize from './data/useOAuthAuthorize'
import useOAuthDisconnect from './data/useOAuthDisconnect'
import { oauthFailureMessage, signInLabels } from './oauthMessages'
import { hasOAuthToken } from './oauthStatus'

const { Text } = Typography

const OAUTH_MESSAGE_TYPE = 'bit-smtp-oauth'
const OAUTH_CHANNEL_NAME = 'bit-smtp-oauth'
const DUPLICATE_MESSAGE_WINDOW_MS = 2000
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

interface PendingSignIn {
  url: string
  wasConnected: boolean
}

/** Type guard for a same-origin OAuth popup callback payload. */
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

/** Opens the consent page in the named sign-in popup; call it from a click so popup blockers allow it. */
function openSignInWindow(url: string) {
  window.open(url, POPUP_NAME, POPUP_FEATURES)
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

/**
 * Subscribes to OAuth popup callbacks for this connection, sent both by opener postMessage and on a same-origin
 * BroadcastChannel (which still works when the provider's COOP header severs the opener); a copy on both is handled once.
 */
function useOAuthCallback(connectionId: string, onMessage: (message: OAuthCallbackMessage) => void) {
  const handlerRef = useRef(onMessage)
  handlerRef.current = onMessage
  const lastDeliveryRef = useRef({ key: '', at: 0 })

  useEffect(() => {
    /** Forwards a valid callback for this connection to the latest handler, skipping the second copy of one just handled. */
    function deliver(data: unknown) {
      if (!isOAuthCallbackMessage(data) || (data.connectionId && data.connectionId !== connectionId)) {
        return
      }
      const key = JSON.stringify(data)
      const now = Date.now()
      const last = lastDeliveryRef.current
      if (key === last.key && now - last.at < DUPLICATE_MESSAGE_WINDOW_MS) {
        return
      }
      lastDeliveryRef.current = { key, at: now }
      handlerRef.current(data)
    }

    /** Accepts window messages only from this site's origin. */
    function handleWindowMessage(event: MessageEvent) {
      if (event.origin === window.location.origin) {
        deliver(event.data)
      }
    }

    /** Accepts channel messages, which only same-origin pages can post. */
    function handleChannelMessage(event: MessageEvent) {
      deliver(event.data)
    }

    window.addEventListener('message', handleWindowMessage)
    const channel =
      typeof BroadcastChannel === 'undefined' ? undefined : new BroadcastChannel(OAUTH_CHANNEL_NAME)
    channel?.addEventListener('message', handleChannelMessage)

    return () => {
      window.removeEventListener('message', handleWindowMessage)
      channel?.close()
    }
  }, [connectionId])
}

/** While `active`, refetches mail settings whenever this window regains focus or becomes visible, so a sign-in finished in the popup shows up even if its message never arrived. */
function useRefetchSettingsOnReturn(active: boolean) {
  const queryClient = useQueryClient()

  useEffect(() => {
    if (!active) {
      return undefined
    }

    /** Refetches settings once the admin page is visible again. */
    function refetchWhenVisible() {
      if (document.visibilityState === 'visible') {
        queryClient.invalidateQueries({ queryKey: MAIL_SETTINGS_QUERY_KEY })
      }
    }

    window.addEventListener('focus', refetchWhenVisible)
    document.addEventListener('visibilitychange', refetchWhenVisible)
    return () => {
      window.removeEventListener('focus', refetchWhenVisible)
      document.removeEventListener('visibilitychange', refetchWhenVisible)
    }
  }, [active, queryClient])
}

/**
 * Sign-in button for OAuth providers: validates and saves the form, opens the consent popup inside the click
 * (so popup blockers allow it), waits for the result, reports plain-language failures, and shows which account is connected.
 */
export default function OAuthConnectButton({
  connectionId,
  provider,
  connected,
  persistConnection,
  onDiscarded
}: {
  connectionId: string
  provider: string
  connected: boolean
  persistConnection: () => Promise<string>
  onDiscarded?: () => void
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
  const [pending, setPending] = useState<PendingSignIn>()
  const [failure, setFailure] = useState<string>()
  const [createdHere, setCreatedHere] = useState(false)
  const finishedWithoutMessage = pending !== undefined && !pending.wasConnected && signIn.connected

  useRefetchSettingsOnReturn(pending !== undefined)

  useEffect(() => {
    if (finishedWithoutMessage) {
      setPending(undefined)
      setFailure(undefined)
      setCreatedHere(false)
      notify.success(__('Account connected'))
    }
  }, [finishedWithoutMessage])

  useOAuthCallback(connectionId, message => {
    setPending(undefined)
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

  /** Marks sign-in as waiting on the popup, remembering whether an account was already connected. */
  const awaitSignIn = (url: string) => setPending({ url, wasConnected: signIn.connected })

  /** Opens the popup synchronously inside the click, then points it at the consent page once it is ready. */
  const handleSignIn = async () => {
    const popup = window.open('', POPUP_NAME, POPUP_FEATURES)
    setBlockedUrl(undefined)
    setPending(undefined)
    setFailure(undefined)

    const url = await prepareSignIn()
    if (!url) {
      popup?.close()
      return
    }
    if (popup && !popup.closed) {
      popup.location.href = url
      awaitSignIn(url)
    } else {
      setBlockedUrl(url)
    }
  }

  /** Retry from the "blocked" notice; a direct click, so the browser allows this popup. */
  const openBlockedUrl = () => {
    if (blockedUrl) {
      openSignInWindow(blockedUrl)
      setBlockedUrl(undefined)
      awaitSignIn(blockedUrl)
    }
  }

  /** Deletes a connection this button saved when sign-in didn't finish, so no half-set-up connection is left behind. */
  const discardConnection = async () => {
    try {
      await deleteConnection(connectionId)
      setCreatedHere(false)
      setPending(undefined)
      setFailure(undefined)
      notify.success(__('Connection removed'))
      onDiscarded?.()
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

  const unfinishedNote = createdHere
    ? __("This connection is saved, but it can't send email until you sign in.")
    : undefined
  const removeButton = createdHere ? (
    <Button size="small" danger loading={isDiscarding} onClick={discardConnection}>
      {__('Remove this connection')}
    </Button>
  ) : null

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
      {pending ? (
        <Alert
          type="info"
          showIcon
          message={labels.finishInWindow}
          description={unfinishedNote}
          action={
            <Space direction="vertical" size="small">
              <Button size="small" onClick={() => openSignInWindow(pending.url)}>
                {__('Open sign-in again')}
              </Button>
              {removeButton}
            </Space>
          }
        />
      ) : null}
      {failure && !signIn.connected ? (
        <Alert
          type="error"
          showIcon
          message={failure}
          description={unfinishedNote}
          action={removeButton}
        />
      ) : null}
    </Flex>
  )
}
