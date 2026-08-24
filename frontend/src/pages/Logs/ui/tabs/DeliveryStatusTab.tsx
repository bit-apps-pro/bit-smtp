import { formatTimestamp } from '@common/helpers/datetime'
import { __ } from '@common/helpers/i18nwrap'
import { FAILURE_CLASS_LABELS } from '@pages/Logs/data/failureClass'
import { type DeliveryEvent, type LogType } from '@pages/Logs/data/useFetchLogs'
import DeliveryStatusTag from '@pages/Logs/ui/DeliveryStatusTag'
import { Flex, Table, type TableColumnsType, Tag, Typography } from 'antd'

const { Text } = Typography

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
    </Flex>
  )
}
