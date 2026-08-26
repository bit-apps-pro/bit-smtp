import { formatTimestamp } from '@common/helpers/datetime'
import { __ } from '@common/helpers/i18nwrap'
import { FAILURE_CLASS_LABELS } from '@pages/Logs/data/failureClass'
import { type DeliveryEvent, type EngagementEvent, type LogType } from '@pages/Logs/data/useFetchLogs'
import DeliveryStatusTag from '@pages/Logs/ui/DeliveryStatusTag'
import { Flex, Table, type TableColumnsType, Tag, Tooltip, Typography } from 'antd'

const { Text } = Typography

/** Human label for an engagement fire type, falling back to the raw value for any future type. */
function engagementTypeLabel(type: string): string {
  if (type === 'click') {
    return __('Click')
  }
  if (type === 'open') {
    return __('Open')
  }

  return type
}

const engagementColumns: TableColumnsType<EngagementEvent> = [
  {
    title: __('Type'),
    dataIndex: 'type',
    key: 'type',
    width: 90,
    render: (type: string) => (
      <Tag color={type === 'click' ? 'geekblue' : 'green'}>{engagementTypeLabel(type)}</Tag>
    )
  },
  {
    title: __('Link'),
    dataIndex: 'target',
    key: 'target',
    ellipsis: true,
    render: (target: string) =>
      target ? (
        <Tooltip title={target}>
          <Text style={{ fontSize: 12 }}>{target}</Text>
        </Tooltip>
      ) : (
        <Text type="secondary">—</Text>
      )
  },
  // Confirmed-human hits keep automated fires (Apple MPP / proxies / prefetch) out of the headline.
  {
    title: __('Human'),
    key: 'human',
    align: 'right',
    width: 80,
    render: (_: unknown, row: EngagementEvent) => Math.max(0, row.hits - row.automated_hits)
  },
  {
    title: __('Automated'),
    dataIndex: 'automated_hits',
    key: 'automated_hits',
    align: 'right',
    width: 100
  },
  {
    title: __('Last activity'),
    dataIndex: 'last_at',
    key: 'last_at',
    render: (lastAt?: string | null) => formatTimestamp(lastAt)
  }
]

const columns: TableColumnsType<DeliveryEvent> = [
  { title: __('Recipient'), dataIndex: 'recipient', key: 'recipient' },
  {
    title: __('Status'),
    dataIndex: 'status',
    key: 'status',
    render: (status: string) => <DeliveryStatusTag status={status} />
  },
  {
    title: __('When'),
    dataIndex: 'occurred_at',
    key: 'occurred_at',
    render: (occurredAt?: string | null) => formatTimestamp(occurredAt)
  }
]

export default function DeliveryStatusTab({ log }: { log: LogType }) {
  const events = log?.delivery_events ?? []
  const engagement = log?.engagement ?? []

  return (
    <Flex vertical gap="middle">
      <Flex align="center" gap="small" wrap>
        <Text strong>{__('Overall')}:</Text>
        <DeliveryStatusTag status={log?.delivery_status} />
        {log?.failure_class ? (
          <Tag color="volcano">
            {`${__('Failure')}: ${FAILURE_CLASS_LABELS[log.failure_class] ?? log.failure_class}`}
          </Tag>
        ) : null}
      </Flex>
      {events.length === 0 ? (
        <Text type="secondary">
          {__('No delivery events yet. This updates once the provider reports an outcome.')}
        </Text>
      ) : (
        <Table<DeliveryEvent>
          rowKey={row => `${row.recipient}-${row.status}-${row.occurred_at}`}
          columns={columns}
          dataSource={events}
          pagination={false}
          size="small"
        />
      )}

      <Text strong>{__('Engagement (opens & clicks)')}</Text>
      {engagement.length === 0 ? (
        <Text type="secondary">
          {__('No opens or clicks recorded. Engagement tracking must be enabled to capture these.')}
        </Text>
      ) : (
        <Table<EngagementEvent>
          rowKey={row => `${row.type}-${row.target}`}
          columns={engagementColumns}
          dataSource={engagement}
          pagination={false}
          size="small"
        />
      )}
    </Flex>
  )
}
