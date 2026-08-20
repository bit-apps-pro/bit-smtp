import { useEffect, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { ApiOutlined } from '@ant-design/icons'
import { __ } from '@common/helpers/i18nwrap'
import {
  DndContext,
  type DragEndEvent,
  PointerSensor,
  closestCenter,
  useSensor,
  useSensors
} from '@dnd-kit/core'
import { SortableContext, arrayMove, rectSortingStrategy, useSortable } from '@dnd-kit/sortable'
import { CSS } from '@dnd-kit/utilities'
import { type Connection, type MailSettings } from '@pages/Connections/types'
import { Button, Col, Flex, Row, Spin, Typography, theme } from 'antd'
import ConnectionCard from './ConnectionCard'
import ProviderSelectorModal from './ProviderSelectorModal'
import useDeleteConnection from './data/useDeleteConnection'
import useMailSettings from './data/useMailSettings'
import useProviders from './data/useProviders'
import useSetDefaultConnection from './data/useSetDefaultConnection'
import useUpdateSettings from './data/useUpdateSettings'

const { Title, Text } = Typography

/** Default connection first, then the saved fallback chain, then any connection missing from both. */
function deriveOrderedIds(settings: MailSettings): string[] {
  const knownIds = new Set(settings.connections.map(connection => connection.id))
  const ordered: string[] = []
  const seen = new Set<string>()

  const addId = (id: string) => {
    if (knownIds.has(id) && !seen.has(id)) {
      ordered.push(id)
      seen.add(id)
    }
  }

  addId(settings.default_connection_id)
  settings.fallback_connection_ids.forEach(addId)
  settings.connections.forEach(connection => addId(connection.id))

  return ordered
}

function SortableConnectionCard({
  connection,
  priority,
  isDefault,
  onSetDefault,
  onEdit,
  onDelete
}: {
  connection: Connection
  priority: number
  isDefault: boolean
  onSetDefault: () => void
  onEdit: () => void
  onDelete: () => void
}) {
  const { attributes, listeners, setNodeRef, transform, transition, isDragging } = useSortable({
    id: connection.id
  })

  const style = {
    transform: CSS.Transform.toString(transform),
    transition,
    opacity: isDragging ? 0.5 : 1
  }

  return (
    <Col ref={setNodeRef} style={style} xs={24} sm={12} lg={8}>
      <ConnectionCard
        connection={connection}
        isDefault={isDefault}
        priority={priority}
        onSetDefault={onSetDefault}
        onEdit={onEdit}
        onDelete={onDelete}
        dragHandleAttributes={attributes}
        dragHandleListeners={listeners}
      />
    </Col>
  )
}

function EmptyConnections({ onAddConnection }: { onAddConnection: () => void }) {
  const { token } = theme.useToken()

  return (
    <Flex
      vertical
      align="center"
      gap="small"
      style={{
        padding: '64px 24px',
        textAlign: 'center',
        border: `1px dashed ${token.colorBorderSecondary}`,
        borderRadius: token.borderRadiusLG
      }}
    >
      <Flex
        align="center"
        justify="center"
        aria-hidden="true"
        style={{
          width: 56,
          height: 56,
          borderRadius: token.borderRadiusLG,
          backgroundColor: token.colorPrimaryBg,
          color: token.colorPrimary,
          fontSize: token.fontSizeHeading3
        }}
      >
        <ApiOutlined />
      </Flex>
      <Title level={5} style={{ margin: 0 }}>
        {__('No connections yet')}
      </Title>
      <Text type="secondary" style={{ maxWidth: 360 }}>
        {__('Add an SMTP server or an email API to start delivering mail through Bit SMTP.')}
      </Text>
      <Button type="primary" onClick={onAddConnection} style={{ marginTop: token.marginXS }}>
        {__('Add connection')}
      </Button>
    </Flex>
  )
}

export default function ConnectionsListPage() {
  const navigate = useNavigate()
  const { data: settings, isPending: isSettingsPending } = useMailSettings()
  const { isPending: isProvidersPending } = useProviders()
  const setDefaultConnection = useSetDefaultConnection()
  const deleteConnection = useDeleteConnection()
  const updateSettings = useUpdateSettings()
  const sensors = useSensors(useSensor(PointerSensor))

  const [orderedIds, setOrderedIds] = useState<string[]>([])
  const [isProviderModalOpen, setIsProviderModalOpen] = useState(false)

  useEffect(() => {
    if (settings) {
      setOrderedIds(deriveOrderedIds(settings))
    }
  }, [settings])

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

  const connectionsById = new Map(settings.connections.map(connection => [connection.id, connection]))
  const orderedConnections = orderedIds
    .map(id => connectionsById.get(id))
    .filter((connection): connection is Connection => connection !== undefined)

  const handleDragEnd = (event: DragEndEvent) => {
    const { active, over } = event
    if (!over || active.id === over.id) {
      return
    }

    const oldIndex = orderedIds.indexOf(String(active.id))
    const newIndex = orderedIds.indexOf(String(over.id))
    if (oldIndex === -1 || newIndex === -1) {
      return
    }

    const newOrder = arrayMove(orderedIds, oldIndex, newIndex)
    setOrderedIds(newOrder)
    updateSettings.mutate({ fallback_connection_ids: newOrder })
  }

  const handleProviderSelected = (providerKey: string) => {
    navigate(`/connection/new?provider=${encodeURIComponent(providerKey)}`)
  }

  const hasConnections = settings.connections.length > 0
  const openProviderModal = () => setIsProviderModalOpen(true)

  return (
    <Flex vertical gap="middle" style={{ padding: 24 }}>
      <Flex justify="space-between" align="center">
        <Title level={4} style={{ margin: 0 }}>
          {__('Connections')}
        </Title>
        {hasConnections && (
          <Button type="primary" onClick={openProviderModal}>
            {__('Add connection')}
          </Button>
        )}
      </Flex>
      <ProviderSelectorModal
        open={isProviderModalOpen}
        onClose={() => setIsProviderModalOpen(false)}
        onSelect={handleProviderSelected}
      />
      {hasConnections ? (
        <DndContext sensors={sensors} collisionDetection={closestCenter} onDragEnd={handleDragEnd}>
          <SortableContext items={orderedIds} strategy={rectSortingStrategy}>
            <Row gutter={[16, 16]}>
              {orderedConnections.map((connection, index) => (
                <SortableConnectionCard
                  key={connection.id}
                  connection={connection}
                  priority={index + 1}
                  isDefault={connection.id === settings.default_connection_id}
                  onSetDefault={() => setDefaultConnection.mutate(connection.id)}
                  onEdit={() => navigate(`/connection/${connection.id}`)}
                  onDelete={() => deleteConnection.mutate(connection.id)}
                />
              ))}
            </Row>
          </SortableContext>
        </DndContext>
      ) : (
        <EmptyConnections onAddConnection={openProviderModal} />
      )}
    </Flex>
  )
}
