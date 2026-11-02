import { useState } from 'react'
import { Link, useNavigate, useParams, useSearchParams } from 'react-router-dom'
import { __ } from '@common/helpers/i18nwrap'
import { type Connection, type MailSettings, type ProviderMeta } from '@pages/Connections/types'
import { Flex, Spin, Typography } from 'antd'
import ConnectionEditor from './ConnectionEditor'
import useMailSettings from './data/useMailSettings'
import useProviders from './data/useProviders'

const { Text } = Typography

/** A blank, unsaved connection for the provider, optionally carrying the id its first save minted. */
function blankConnection(provider: ProviderMeta, id = ''): Connection {
  return {
    id,
    provider: provider.key,
    kind: provider.kind,
    name: '',
    enabled: true,
    fromEmail: '',
    fromName: '',
    replyToEmail: '',
    settings: {},
    credentials: {}
  }
}

/** The connection a route shows: the saved one for an id (or the one just created here until settings catch up), else a blank draft for the requested provider. */
function routeConnection(
  id: string | undefined,
  settings: MailSettings | undefined,
  newProvider: ProviderMeta | undefined,
  createdHere: Connection | undefined
): Connection | undefined {
  if (id) {
    return (
      settings?.connections.find(item => item.id === id) ??
      (createdHere?.id === id ? createdHere : undefined)
    )
  }
  return newProvider ? blankConnection(newProvider) : undefined
}

/**
 * Create (`/connection/new?provider=…`) and edit (`/connection/:id`) page. Both routes render this one component, so when
 * a new connection's first save swaps the URL to its edit route the editor, and any sign-in in progress, stays mounted.
 */
export default function ConnectionDetailPage() {
  const { id } = useParams()
  const [searchParams] = useSearchParams()
  const navigate = useNavigate()
  const { data: settings, isPending: isSettingsPending } = useMailSettings()
  const { data: providers, isPending: isProvidersPending } = useProviders()
  const [createdHere, setCreatedHere] = useState<Connection>()

  const newProvider = id ? undefined : providers?.find(item => item.key === searchParams.get('provider'))
  const connection = routeConnection(id, settings, newProvider, createdHere)
  const provider = providers?.find(item => item.key === connection?.provider)

  if (!connection || !provider) {
    if (isProvidersPending || (id && isSettingsPending)) {
      return (
        <Flex justify="center" style={{ padding: 48 }}>
          <Spin size="large" />
        </Flex>
      )
    }

    return (
      <Flex vertical gap="middle" style={{ padding: 24 }}>
        <Text>{id ? __('Connection not found') : __('Select a provider to create a connection')}</Text>
        <Link to="/">{__('Back to connections')}</Link>
      </Flex>
    )
  }

  /** Points the URL at the new connection's edit route, so a reload reopens it instead of a blank form. */
  const handleCreated = (createdId: string) => {
    setCreatedHere(blankConnection(provider, createdId))
    navigate(`/connection/${createdId}`, { replace: true })
  }

  return (
    <Flex vertical gap="middle" style={{ padding: 24 }}>
      <Link to="/">{__('Back to connections')}</Link>
      <ConnectionEditor
        connection={connection}
        provider={provider}
        onSaved={() => navigate('/')}
        onCreated={handleCreated}
        onDiscarded={() => navigate('/')}
      />
    </Flex>
  )
}
