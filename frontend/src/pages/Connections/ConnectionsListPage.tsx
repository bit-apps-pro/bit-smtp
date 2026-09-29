import { useEffect, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { ApiOutlined } from '@ant-design/icons'
import { __ } from '@common/helpers/i18nwrap'
import notify from '@components/Toaster/Toaster'
import {
  DndContext,
  type DragEndEvent,
  KeyboardSensor,
  PointerSensor,
  closestCenter,
  useSensor,
  useSensors
} from '@dnd-kit/core'
import {
  SortableContext,
  arrayMove,
  rectSortingStrategy,
  sortableKeyboardCoordinates,
  useSortable
} from '@dnd-kit/sortable'
import { CSS } from '@dnd-kit/utilities'
import { type Connection, type ConnectionHealth, type MailSettings } from '@pages/Connections/types'
import { countRulesByConnection } from '@pages/Routing/routingRules'
import { Button, Col, Flex, Row, Spin, Typography, theme } from 'antd'
import ConnectionCard from './ConnectionCard'
import ProviderSelectorModal from './ProviderSelectorModal'
import useCheckConnectionHealth from './data/useCheckConnectionHealth'
import useConnectionHealth from './data/useConnectionHealth'
import useDeleteConnection from './data/useDeleteConnection'
import useMailSettings from './data/useMailSettings'
import useProviders from './data/useProviders'
import useSetDefaultConnection from './data/useSetDefaultConnection'
import useToggleConnectionEnabled from './data/useToggleConnectionEnabled'
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

/** Moves `activeId` to `overId`'s slot, never displacing a locked primary from slot 1; null when nothing changes. */
function reorderBackups(
  orderedIds: string[],
  activeId: string,
  overId: string,
  primaryId: string
): string[] | null {
  const oldIndex = orderedIds.indexOf(activeId)
  const overIndex = orderedIds.indexOf(overId)
  if (oldIndex === -1 || overIndex === -1 || activeId === primaryId) {
    return null
  }

  const firstBackupIndex = orderedIds[0] === primaryId ? 1 : 0
  const newIndex = Math.max(overIndex, firstBackupIndex)
  return newIndex === oldIndex ? null : arrayMove(orderedIds, oldIndex, newIndex)
}

/** Drag-sortable wrapper around ConnectionCard, wired to dnd-kit's sortable context. */
function SortableConnectionCard({
  connection,
  backupNumber,
  routingRuleCount,
  isDefault,
  health,
  onSetDefault,
  onToggleEnabled,
  onEdit,
  onDelete,
  isToggling
}: {
  connection: Connection
  backupNumber: number
  routingRuleCount: number
  isDefault: boolean
  health?: ConnectionHealth
  onSetDefault: () => void
  onToggleEnabled: (enabled: boolean) => void
  onEdit: () => void
  onDelete: () => void
  isToggling: boolean
}) {
  const { attributes, listeners, setNodeRef, transform, transition, isDragging } = useSortable({
    id: connection.id,
    disabled: isDefault
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
        backupNumber={backupNumber}
        routingRuleCount={routingRuleCount}
        health={health}
        onSetDefault={onSetDefault}
        onToggleEnabled={onToggleEnabled}
        onEdit={onEdit}
        onDelete={onDelete}
        isToggling={isToggling}
        dragHandleAttributes={attributes}
        dragHandleListeners={listeners}
      />
    </Col>
  )
}

/** Dashed-border empty state shown when no connection has been added yet. */
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
      <Text type="secondary" style={{ maxWidth: 420, fontSize: token.fontSizeSM }}>
        {__(
          'Not sure which to pick? Other SMTP works with most hosts. Services like Brevo or Postmark reach inboxes more reliably.'
        )}
      </Text>
      <Button type="primary" onClick={onAddConnection} style={{ marginTop: token.marginXS }}>
        {__('Add connection')}
      </Button>
    </Flex>
  )
}

/** Connections page: a locked primary plus drag/keyboard-reorderable backups, with health, primary, and enable controls. */
export default function ConnectionsListPage() {
  const { token } = theme.useToken()
  const navigate = useNavigate()
  const { data: settings, isPending: isSettingsPending } = useMailSettings()
  const { isPending: isProvidersPending } = useProviders()
  const { health } = useConnectionHealth()
  const checkConnectionHealth = useCheckConnectionHealth()
  const setDefaultConnection = useSetDefaultConnection()
  const toggleEnabled = useToggleConnectionEnabled()
  const deleteConnection = useDeleteConnection()
  const updateSettings = useUpdateSettings()
  const sensors = useSensors(
    useSensor(PointerSensor),
    useSensor(KeyboardSensor, { coordinateGetter: sortableKeyboardCoordinates })
  )

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

  /** Moves a backup to its drop slot, persists the failover order, and reverts it if the save fails. */
  const handleDragEnd = ({ active, over }: DragEndEvent) => {
    if (!over) {
      return
    }

    const newOrder = reorderBackups(
      orderedIds,
      String(active.id),
      String(over.id),
      settings.default_connection_id
    )
    if (!newOrder) {
      return
    }

    const previousOrder = orderedIds
    const revert = () => {
      setOrderedIds(previousOrder)
      notify.error(__("Couldn't save the failover order."))
    }

    setOrderedIds(newOrder)
    updateSettings.mutate(
      { fallback_connection_ids: newOrder },
      {
        onSuccess: response =>
          response.status === 'success' ? notify.success(__('Failover order saved.')) : revert(),
        onError: revert
      }
    )
  }

  /** Probes every connection's health and reports when the check has finished. */
  const handleCheckHealth = () => {
    checkConnectionHealth.mutate(undefined, {
      onSuccess: () => notify.success(__('Connection health checked.')),
      onError: () => notify.error(__("Couldn't check connection health."))
    })
  }

  /** Opens the new-connection editor for the provider picked in the selector. */
  const handleProviderSelected = (providerKey: string) => {
    navigate(`/connection/new?provider=${encodeURIComponent(providerKey)}`)
  }

  const hasConnections = settings.connections.length > 0
  const hasPrimary = orderedIds[0] === settings.default_connection_id
  const rulesByConnection = countRulesByConnection(settings)
  const openProviderModal = () => setIsProviderModalOpen(true)

  return (
    <Flex vertical gap="middle" style={{ padding: 24 }}>
      <Flex justify="space-between" align="center" gap="middle" wrap>
        <Flex vertical gap={4} style={{ flex: '1 1 320px' }}>
          <Title level={4} style={{ margin: 0 }}>
            {__('Connections')}
          </Title>
          {hasConnections && (
            <>
              <Text type="secondary">
                {__(
                  'Emails go out through your primary connection. If it fails, Bit SMTP tries each backup in order.'
                )}
              </Text>
              <Text type="secondary" style={{ fontSize: token.fontSizeSM }}>
                {__('Drag a backup to change the order. Disabled connections are skipped.')}
              </Text>
            </>
          )}
        </Flex>
        {hasConnections && (
          <Flex gap="small" wrap>
            <Button onClick={handleCheckHealth} loading={checkConnectionHealth.isPending}>
              {__('Check connection health')}
            </Button>
            <Button type="primary" onClick={openProviderModal}>
              {__('Add connection')}
            </Button>
          </Flex>
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
                  backupNumber={hasPrimary ? index : index + 1}
                  routingRuleCount={rulesByConnection.get(connection.id) ?? 0}
                  isDefault={connection.id === settings.default_connection_id}
                  health={health[connection.id]}
                  onSetDefault={() => setDefaultConnection.mutate(connection.id)}
                  onToggleEnabled={enabled => toggleEnabled.mutate({ id: connection.id, enabled })}
                  onEdit={() => navigate(`/connection/${connection.id}`)}
                  onDelete={() => deleteConnection.mutate(connection.id)}
                  isToggling={toggleEnabled.isPending && toggleEnabled.variables?.id === connection.id}
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
