import { formatTimestamp } from '@common/helpers/datetime'
import { __, sprintf } from '@common/helpers/i18nwrap'
import notify from '@components/Toaster/Toaster'
import { FAILURE_CLASS_LABELS } from '@pages/Logs/data/failureClass'
import SettingsPanel from '@pages/Settings/components/SettingsPanel'
import useFlushRetryQueue from '@pages/Settings/data/useFlushRetryQueue'
import useRetryQueue, { type RetryQueueItem } from '@pages/Settings/data/useRetryQueue'
import {
  Alert,
  Button,
  Empty,
  Flex,
  Popconfirm,
  Statistic,
  Table,
  type TableColumnsType,
  Tag
} from 'antd'
import { RefreshCw } from 'lucide-react'

/** Render a failover connection_chain (comma-joined) as a row of tags. */
const renderConnectionChain = (chain: string) => {
  const names = chain
    .split(',')
    .map(name => name.trim())
    .filter(Boolean)
  if (names.length === 0) return '—'
  return (
    <Flex gap={4} wrap>
      {names.map(name => (
        <Tag key={name}>{name}</Tag>
      ))}
    </Flex>
  )
}

const columns: TableColumnsType<RetryQueueItem> = [
  {
    title: __('Failure'),
    dataIndex: 'failure_class',
    key: 'failure_class',
    render: (failureClass: string | null) =>
      failureClass ? (
        <Tag color="volcano">{FAILURE_CLASS_LABELS[failureClass] ?? failureClass}</Tag>
      ) : (
        '—'
      )
  },
  {
    title: __('Attempts'),
    key: 'attempts',
    render: (_, record) => `${record.attempts}/${record.max_attempts}`
  },
  {
    title: __('Connection(s)'),
    dataIndex: 'connection_chain',
    key: 'connection_chain',
    render: renderConnectionChain
  },
  {
    title: __('Next attempt'),
    dataIndex: 'next_attempt_at',
    key: 'next_attempt_at',
    render: formatTimestamp
  },
  {
    title: __('Locked'),
    dataIndex: 'locked',
    key: 'locked',
    render: (locked: boolean) => (locked ? <Tag color="processing">{__('Processing')}</Tag> : '—')
  }
]

/** Live panel of deferred sends awaiting retry: queue depth, a refresh, a destructive clear, and the list. */
export default function RetryQueuePanel() {
  const { enabled, depth, items, isLoading, isFetching, refetch } = useRetryQueue()
  const flush = useFlushRetryQueue()

  /** Confirm-gated flush that toasts the outcome; the cached queue is refreshed by the mutation hook. */
  const handleFlush = () => {
    flush.mutate(undefined, {
      onSuccess: result => {
        if (result.ok) {
          notify.success(result.message || sprintf(__('Cleared %d queued retries'), result.deleted))
          return
        }
        notify.error(result.message || __('Could not clear the retry queue'))
      },
      onError: () => notify.error(__('Could not clear the retry queue'))
    })
  }

  return (
    <SettingsPanel
      intro={__(
        'Sends that failed with a retryable error wait here for their next attempt. Use Refresh to see the latest.'
      )}
    >
      <Flex vertical gap="middle">
        {!enabled && depth > 0 ? (
          <Alert
            type="warning"
            showIcon
            message={__('Automatic retry is turned off')}
            description={__(
              'These queued sends will not be attempted until you enable automatic retry above and save.'
            )}
          />
        ) : null}
        <Flex align="center" justify="space-between" gap="middle" wrap>
          <Statistic title={__('Queued retries')} value={depth} />
          <Flex gap="small" wrap>
            <Button
              icon={<RefreshCw size={16} />}
              onClick={() => {
                refetch()
              }}
              loading={isFetching}
            >
              {__('Refresh')}
            </Button>
            {depth > 0 ? (
              <Popconfirm
                title={__('Clear the retry queue?')}
                description={__(
                  'Discarding queued retries abandons those sends — they will not be delivered.'
                )}
                okText={__('Discard')}
                cancelText={__('Cancel')}
                okButtonProps={{ danger: true }}
                onConfirm={handleFlush}
              >
                <Button danger loading={flush.isPending}>
                  {__('Clear queue')}
                </Button>
              </Popconfirm>
            ) : null}
          </Flex>
        </Flex>
        {depth === 0 && !isLoading ? (
          <Empty description={__('No retries queued')} />
        ) : (
          <Table<RetryQueueItem>
            rowKey="id"
            columns={columns}
            dataSource={items}
            pagination={false}
            size="small"
            loading={isLoading}
            footer={
              depth > items.length
                ? () => sprintf(__('Showing the first %d of %d queued retries.'), items.length, depth)
                : undefined
            }
          />
        )}
      </Flex>
    </SettingsPanel>
  )
}
