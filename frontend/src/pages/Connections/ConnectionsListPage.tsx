import { useNavigate } from 'react-router-dom'
import { __ } from '@common/helpers/i18nwrap'
import { Button, Col, Flex, Row, Spin, Typography } from 'antd'
import ConnectionCard from './ConnectionCard'
import useDeleteConnection from './data/useDeleteConnection'
import useMailSettings from './data/useMailSettings'
import useProviders from './data/useProviders'
import useSetDefaultConnection from './data/useSetDefaultConnection'

const { Title } = Typography

export default function ConnectionsListPage() {
  const navigate = useNavigate()
  const { data: settings, isPending: isSettingsPending } = useMailSettings()
  const { isPending: isProvidersPending } = useProviders()
  const setDefaultConnection = useSetDefaultConnection()
  const deleteConnection = useDeleteConnection()

  if (isSettingsPending || isProvidersPending) {
    return (
      <Flex justify="center" style={{ padding: 48 }}>
        <Spin size="large" />
      </Flex>
    )
  }

  if (!settings) {
    return null
  }

  return (
    <Flex vertical gap="middle" style={{ padding: 24 }}>
      <Flex justify="space-between" align="center">
        <Title level={4} style={{ margin: 0 }}>
          {__('Connections')}
        </Title>
        <Button type="primary" onClick={() => navigate('/connection/new')}>
          {__('Add connection')}
        </Button>
      </Flex>
      <Row gutter={[16, 16]}>
        {settings.connections.map(connection => (
          <Col key={connection.id} xs={24} sm={12} lg={8}>
            <ConnectionCard
              connection={connection}
              isDefault={connection.id === settings.default_connection_id}
              onSetDefault={() => setDefaultConnection.mutate(connection.id)}
              onEdit={() => navigate(`/connection/${connection.id}`)}
              onDelete={() => deleteConnection.mutate(connection.id)}
            />
          </Col>
        ))}
      </Row>
    </Flex>
  )
}
