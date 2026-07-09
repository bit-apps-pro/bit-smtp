import { useEffect, useState } from 'react'
import { __ } from '@common/helpers/i18nwrap'
import { type Connection, type ProviderMeta } from '@pages/Connections/types'
import { Button, Card, Col, Flex, Row, Spin } from 'antd'
import ConnectionEditor from './ConnectionEditor'
import ConnectionList from './ConnectionList'
import DefaultConnectionSelect from './DefaultConnectionSelect'
import ProviderPicker from './ProviderPicker'
import useDeleteConnection from './data/useDeleteConnection'
import useMailSettings from './data/useMailSettings'
import useProviders from './data/useProviders'
import useSetDefaultConnection from './data/useSetDefaultConnection'

function buildBlankConnection(provider: ProviderMeta): Connection {
  const settings = Object.fromEntries(
    provider.fields.filter(field => !field.secret).map(field => [field.key, field.default])
  )

  return {
    id: '',
    provider: provider.key,
    kind: provider.kind,
    name: '',
    enabled: true,
    fromEmail: '',
    fromName: '',
    replyToEmail: '',
    settings,
    credentials: {}
  }
}

export default function ConnectionsPage() {
  const { data: settings, isPending: isSettingsPending } = useMailSettings()
  const { data: providers, isPending: isProvidersPending } = useProviders()
  const deleteConnection = useDeleteConnection()
  const setDefaultConnection = useSetDefaultConnection()

  const [selectedId, setSelectedId] = useState<string | null>(null)
  const [addingProviderKey, setAddingProviderKey] = useState<string | null>(null)

  useEffect(() => {
    if (!settings || selectedId) {
      return
    }
    setSelectedId(settings.default_connection_id || settings.connections[0]?.id || null)
  }, [settings, selectedId])

  if (isSettingsPending || isProvidersPending) {
    return (
      <Flex justify="center" style={{ padding: 48 }}>
        <Spin size="large" />
      </Flex>
    )
  }

  if (!settings || !providers) {
    return null
  }

  const providerFor = (providerKey: string): ProviderMeta | undefined =>
    providers.find(provider => provider.key === providerKey)

  const isAdding = addingProviderKey !== null
  const addingProvider = addingProviderKey ? providerFor(addingProviderKey) : undefined
  const selectedConnection =
    settings.connections.find(connection => connection.id === selectedId) ?? null
  const selectedProvider = selectedConnection ? providerFor(selectedConnection.provider) : undefined

  const editorConnection =
    isAdding && addingProvider ? buildBlankConnection(addingProvider) : selectedConnection
  const editorProvider = isAdding ? addingProvider : selectedProvider

  const handleSelect = (id: string) => {
    setAddingProviderKey(null)
    setSelectedId(id)
  }

  const handleStartAdding = () => {
    setSelectedId(null)
    setAddingProviderKey(providers[0]?.key ?? null)
  }

  const handleSaved = () => {
    if (isAdding) {
      setAddingProviderKey(null)
    }
  }

  return (
    <Row gutter={24} style={{ padding: 24 }}>
      <Col span={8}>
        <Flex vertical gap="middle">
          <DefaultConnectionSelect
            connections={settings.connections}
            defaultId={settings.default_connection_id}
            onChange={id => setDefaultConnection.mutate(id)}
          />
          <ConnectionList
            connections={settings.connections}
            defaultId={settings.default_connection_id}
            selectedId={isAdding ? null : selectedId}
            onSelect={handleSelect}
            onDelete={id => deleteConnection.mutate(id)}
          />
          <Button type="dashed" block onClick={handleStartAdding}>
            {__('Add connection')}
          </Button>
        </Flex>
      </Col>
      <Col span={16}>
        {isAdding && (
          <Card style={{ marginBottom: 16 }}>
            <ProviderPicker
              providers={providers}
              value={addingProviderKey ?? ''}
              onChange={setAddingProviderKey}
            />
          </Card>
        )}
        {editorConnection && editorProvider && (
          <ConnectionEditor
            key={editorConnection.id || editorProvider.key}
            connection={editorConnection}
            provider={editorProvider}
            onSaved={handleSaved}
          />
        )}
      </Col>
    </Row>
  )
}
