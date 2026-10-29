import { failureClassEntry } from '@common/glossary'
import { formatTimestamp } from '@common/helpers/datetime'
import { __, sprintf } from '@common/helpers/i18nwrap'
import { HelpPopover } from '@components/FieldHelp'
import notify from '@components/Toaster/Toaster'
import SettingsPanel from '@pages/Settings/components/SettingsPanel'
import useFlushRetryQueue from '@pages/Settings/data/useFlushRetryQueue'
import useRetryQueue, { type RetryQueueItem } from '@pages/Settings/data/useRetryQueue'
import failureTypesHelp from '@pages/Settings/sections/failureTypesHelp'
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

/** Queue table columns; the failure column uses the shared glossary labels plus the "why did it fail" popover. */
const buildColumns = (): TableColumnsType<RetryQueueItem> => [
  {
    title: (
      <Flex align="center" gap={2}>
        {__('Why it failed')}
        <HelpPopover label={__('Help: why it failed')} details={failureTypesHelp()} />
      </Flex>
    ),
    dataIndex: 'failure_class',
    key: 'failure_class',
    render: (failureClass: string | null) =>
      failureClass ? <Tag color="volcano">{failureClassEntry(failureClass).label}</Tag> : '—'
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
          notify.success(result.message || sprintf(__('Removed %d waiting emails'), result.deleted))
          return
        }
        notify.error(result.message || __('Couldn’t clear the list. Please try again.'))
      },
      onError: () => notify.error(__('Couldn’t clear the list. Please try again.'))
    })
  }

  return (
    <SettingsPanel
      intro={__('Emails waiting for their next try are listed here. Click Refresh to see the latest.')}
    >
      <Flex vertical gap="middle">
        {!enabled && depth > 0 ? (
          <Alert
            type="warning"
            showIcon
            message={__('“Try failed emails again” is off')}
            description={__('These emails won’t be sent until you turn it back on and save.')}
          />
        ) : null}
        <Flex align="center" justify="space-between" gap="middle" wrap>
          <Statistic title={__('Waiting to be sent again')} value={depth} />
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
                title={__('Clear the waiting list?')}
                description={__('These emails will never be sent. This can’t be undone.')}
                okText={__('Remove emails')}
                cancelText={__('Cancel')}
                okButtonProps={{ danger: true }}
                onConfirm={handleFlush}
              >
                <Button danger loading={flush.isPending}>
                  {__('Clear list')}
                </Button>
              </Popconfirm>
            ) : null}
          </Flex>
        </Flex>
        {depth === 0 && !isLoading ? (
          <Empty description={__('No emails are waiting to be sent again')} />
        ) : (
          <Table<RetryQueueItem>
            rowKey="id"
            columns={buildColumns()}
            dataSource={items}
            pagination={false}
            size="small"
            loading={isLoading}
            footer={
              depth > items.length
                ? () =>
                    sprintf(__('Showing the first %1$d of %2$d waiting emails.'), items.length, depth)
                : undefined
            }
          />
        )}
      </Flex>
    </SettingsPanel>
  )
}
