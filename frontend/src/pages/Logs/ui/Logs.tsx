import { useEffect, useRef, useState } from 'react'
import { useNavigate, useSearchParams } from 'react-router-dom'
import { __ } from '@common/helpers/i18nwrap'
import useMailSettings from '@pages/Connections/data/useMailSettings'
import useDeleteLog from '@pages/Logs/data/useDeleteLog'
import { type LogQueryType, type LogType } from '@pages/Logs/data/useFetchLogs'
import useFetchLogs from '@pages/Logs/data/useFetchLogs'
import useResendLogs from '@pages/Logs/data/useResendLogs'
import useMailSources from '@pages/Routing/data/useMailSources'
import {
  Button,
  Card,
  DatePicker,
  Flex,
  Input,
  Select,
  Table,
  type TableColumnsType,
  type TableProps,
  Tag,
  Typography,
  notification,
  theme
} from 'antd'
import dayjs, { type Dayjs } from 'dayjs'
import DeliveryStatusTag, { DELIVERY_STATUS_OPTIONS, deliveryStatusLabel } from './DeliveryStatusTag'
import LogRetentionSettings from './LogRetentionSettings'
import LogToggle from './LogToggle'

const { Title, Text } = Typography
const { RangePicker } = DatePicker

type TableRowSelection<T extends object = object> = TableProps<T>['rowSelection']

/** URL/query keys the Logs page renders as removable filter chips, in display order. */
const FILTER_CHIP_KEYS = [
  'status',
  'delivery_status',
  'connection_id',
  'source_plugin',
  'date_from',
  'date_to',
  'to_addr'
] as const

type FilterChipKey = (typeof FILTER_CHIP_KEYS)[number]
type DateRangeValue = [Dayjs, Dayjs] | null

const FILTER_CONTROL_WIDTH = 180
const MAX_CHIP_ID_LENGTH = 12

/** Send-status filter options; the backend maps these to its internal success/error log status. */
const SEND_STATUS_OPTIONS = [
  { value: 'sent', label: __('Sent') },
  { value: 'failed', label: __('Failed') }
]

const SEND_STATUS_LABEL: Record<string, string> = Object.fromEntries(
  SEND_STATUS_OPTIONS.map(option => [option.value, option.label])
)

/** Shortens a long identifier (e.g. a connection id) for compact chip display. */
function truncateForChip(value: string): string {
  return value.length > MAX_CHIP_ID_LENGTH ? `${value.slice(0, MAX_CHIP_ID_LENGTH)}…` : value
}

/** Builds a value→label lookup from a list of {value, label} options, for resolving a chip's display name. */
function labelLookup(options: Array<{ value: string; label: string }>): Record<string, string> {
  return Object.fromEntries(options.map(option => [option.value, option.label]))
}

/** Human-readable label for one active filter chip, keyed to the URL/query param it represents. */
function chipLabel(
  key: FilterChipKey,
  value: string,
  context: { connectionNameById: Record<string, string>; sourceLabelByValue: Record<string, string> }
): string {
  switch (key) {
    case 'status':
      return `${__('Send status')}: ${SEND_STATUS_LABEL[value] ?? value}`
    case 'delivery_status':
      return `${__('Delivery')}: ${deliveryStatusLabel(value)}`
    case 'source_plugin':
      return `${__('Source')}: ${context.sourceLabelByValue[value] ?? value}`
    case 'connection_id':
      return `${__('Connection')}: ${truncateForChip(context.connectionNameById[value] ?? value)}`
    case 'date_from':
      return `${__('From')} ${value}`
    case 'date_to':
      return `${__('To')} ${value}`
    case 'to_addr':
    default:
      return `${__('To addr')}: ${value}`
  }
}

/** Reads the initial log filters carried in the URL (e.g. deep-linked from Analytics "View in logs"). */
function filtersFromSearchParams(searchParams: URLSearchParams): Partial<LogQueryType> {
  const filters: Partial<LogQueryType> = {}
  FILTER_CHIP_KEYS.forEach(key => {
    const value = searchParams.get(key)
    if (value) filters[key] = value
  })
  return filters
}

const failedCount = (attempts?: Array<{ status: string }>) => {
  if (!attempts) return 0
  return attempts.filter(a => a.status === 'failed').length
}

const columns: TableColumnsType<LogType> = [
  {
    // One status cell (merges the old redundant Status + Delivery columns): a failed send shows
    // "Failed"; a proven-live webhook connection shows its real provider outcome (Delivered/Bounced/
    // …/Pending); otherwise a neutral "Sent" — handed to the provider with no delivery signal.
    title: __('Status'),
    key: 'status',
    render: (_, record) => {
      if (!record.status) {
        return <Tag color="red">{__('Failed')}</Tag>
      }
      if (record.delivery_verified) {
        return <DeliveryStatusTag status={record.delivery_status} />
      }

      return <Tag>{__('Sent')}</Tag>
    }
  },
  {
    title: __('Connection'),
    dataIndex: 'connection',
    key: 'connection',
    render: (connection, record) => {
      const attempts = record.details?.attempts
      // Only annotate when the send actually fell back; a lone failed attempt is already
      // conveyed by the Status column.
      const failed = attempts && attempts.length > 1 ? failedCount(attempts) : 0
      if (failed > 0) {
        return (
          <div>
            {connection || '—'}
            <Text type="secondary" style={{ fontSize: '0.85em', marginLeft: '0.5em' }}>
              · {failed} failed
            </Text>
          </div>
        )
      }
      return connection || '—'
    }
  },
  { title: __('Subject'), dataIndex: 'subject', key: 'subject' },
  { title: __('To'), dataIndex: 'to_addr', key: 'to_addr' },
  { title: __('Retry'), dataIndex: 'retry_count', key: 'retry_count' },
  { title: __('Sent At'), dataIndex: 'created_at', key: 'created_at' }
]

export default function Logs() {
  const { token } = theme.useToken()
  const [searchParams, setSearchParams] = useSearchParams()
  const [query, setQuery] = useState<LogQueryType>(() => ({
    pageNo: 1,
    limit: 20,
    ...filtersFromSearchParams(searchParams)
  }))
  const { isLoading, isLogsFetching, logs, total, refetch } = useFetchLogs(query)
  const { isLogDeleting, deleteLog } = useDeleteLog()
  const { isResending, resendLogs } = useResendLogs()
  const { data: mailSettings, isPending: isConnectionsLoading } = useMailSettings()
  const { data: mailSources, isPending: isSourcesLoading } = useMailSources()
  const [selectedRowKeys, setSelectedRowKeys] = useState<React.Key[]>([])
  const navigate = useNavigate()

  const [toAddrInput, setToAddrInput] = useState(() => searchParams.get('to_addr') ?? '')
  const debounceRef = useRef<number | null>(null)

  const connectionOptions = (mailSettings?.connections ?? []).map(connection => ({
    value: connection.id,
    label: connection.name
  }))
  const sourceOptions = (mailSources ?? []).map(source => ({ value: source.value, label: source.label }))
  const connectionNameById = labelLookup(connectionOptions)
  const sourceLabelByValue = labelLookup(sourceOptions)

  /** Removes a filter param from the URL without touching unrelated params or pushing a history entry. */
  const removeSearchParam = (key: FilterChipKey) => {
    setSearchParams(
      prev => {
        const next = new URLSearchParams(prev)
        next.delete(key)
        return next
      },
      { replace: true }
    )
  }

  /** Sets (or, when value is empty, clears) one filter in both query state and the URL; resets to page 1. */
  const setFilterParam = (key: FilterChipKey, value?: string) => {
    setQuery(prev => ({ ...prev, pageNo: 1, [key]: value || undefined }))
    if (value) {
      setSearchParams(
        prev => {
          const next = new URLSearchParams(prev)
          next.set(key, value)
          return next
        },
        { replace: true }
      )
    } else {
      removeSearchParam(key)
    }
  }

  /** Cancels any in-flight to_addr search debounce so a stale timeout can't re-add a just-cleared filter. */
  const cancelToAddrDebounce = () => {
    if (debounceRef.current) window.clearTimeout(debounceRef.current)
  }

  const clearFilter = (key: FilterChipKey) => {
    if (key === 'to_addr') {
      cancelToAddrDebounce()
      setToAddrInput('')
    }
    setFilterParam(key, undefined)
  }

  const clearAllFilters = () => {
    cancelToAddrDebounce()
    setQuery(prev => {
      const next = { ...prev, pageNo: 1 }
      FILTER_CHIP_KEYS.forEach(key => {
        delete next[key]
      })
      return next
    })
    setSearchParams(
      prev => {
        const next = new URLSearchParams(prev)
        FILTER_CHIP_KEYS.forEach(key => next.delete(key))
        return next
      },
      { replace: true }
    )
    setToAddrInput('')
  }

  const activeChips = FILTER_CHIP_KEYS.map(key => ({ key, value: query[key] })).filter(
    (chip): chip is { key: FilterChipKey; value: string } => Boolean(chip.value)
  )

  const handleDelete = () => {
    deleteLog(selectedRowKeys as number[]).then(res => {
      if (res.code === 'SUCCESS') {
        setSelectedRowKeys([])
        refetch()
        notification.success({ message: res?.message || 'Log deleted successfully' })
      } else {
        notification.error({ message: res?.message || 'Failed to delete logs' })
      }
    })
  }

  const handleResend = () => {
    resendLogs(selectedRowKeys as number[]).then(res => {
      if (res.code === 'SUCCESS') {
        setSelectedRowKeys([])
      } else {
        notification.error({ message: res?.message || 'Failed to resend' })
      }
    })
  }

  const onSelectChange = (newSelectedRowKeys: React.Key[]) => {
    setSelectedRowKeys(newSelectedRowKeys)
  }

  const rowSelection: TableRowSelection<LogType> = {
    selectedRowKeys,
    onChange: onSelectChange
  }

  const hasSelected = selectedRowKeys.length > 0
  const onChange = (page: number, pageSize: number) => {
    setQuery(prev => ({ ...prev, pageNo: page, limit: pageSize }))
  }

  const onRowClick = (record: LogType) => ({
    onClick: () => navigate(`/logs/${record.id}`),
    style: { cursor: 'pointer' }
  })

  const handleToAddrChange = (e: React.ChangeEvent<HTMLInputElement>) => {
    const { value } = e.target
    setToAddrInput(value)
    if (debounceRef.current) {
      window.clearTimeout(debounceRef.current)
    }

    debounceRef.current = window.setTimeout(() => {
      setFilterParam('to_addr', value || undefined)
    }, 500) as unknown as number
  }

  const dateRangeValue: DateRangeValue =
    query.date_from && query.date_to ? [dayjs(query.date_from), dayjs(query.date_to)] : null

  /** Commits a picked date range as YYYY-MM-DD strings; clearing the picker clears both bounds. */
  const handleDateRangeChange = (dates: [Dayjs | null, Dayjs | null] | null) => {
    const [from, to] = dates ?? [null, null]
    const dateFrom = from ? from.format('YYYY-MM-DD') : undefined
    const dateTo = to ? to.format('YYYY-MM-DD') : undefined
    setQuery(prev => ({ ...prev, pageNo: 1, date_from: dateFrom, date_to: dateTo }))
    setSearchParams(
      prev => {
        const next = new URLSearchParams(prev)
        if (dateFrom) next.set('date_from', dateFrom)
        else next.delete('date_from')
        if (dateTo) next.set('date_to', dateTo)
        else next.delete('date_to')
        return next
      },
      { replace: true }
    )
  }

  useEffect(
    () => () => {
      if (debounceRef.current) window.clearTimeout(debounceRef.current)
    },
    []
  )

  return (
    <Flex gap="middle" vertical style={{ padding: token.paddingLG }}>
      <Title level={4} style={{ margin: 0 }}>
        {__('Logs')}
      </Title>
      <Card styles={{ body: { display: 'flex', flexDirection: 'column', gap: token.padding } }}>
        <Flex align="center" gap="middle" justify="space-between" wrap>
          <Input.Search
            placeholder={__('Search To address')}
            value={toAddrInput}
            onChange={handleToAddrChange}
            allowClear
            style={{ width: 260 }}
            enterButton={false}
          />
          <Flex align="center" gap="small">
            <LogToggle />
            <LogRetentionSettings />
          </Flex>
        </Flex>

        <Flex gap="small" wrap>
          <Select
            aria-label={__('Send status')}
            placeholder={__('Send status')}
            allowClear
            value={query.status}
            options={SEND_STATUS_OPTIONS}
            style={{ width: FILTER_CONTROL_WIDTH }}
            onChange={value => setFilterParam('status', value)}
          />
          <Select
            aria-label={__('Delivery status')}
            placeholder={__('Delivery status')}
            allowClear
            value={query.delivery_status}
            options={DELIVERY_STATUS_OPTIONS}
            style={{ width: FILTER_CONTROL_WIDTH }}
            onChange={value => setFilterParam('delivery_status', value)}
          />
          <Select
            aria-label={__('Connection')}
            placeholder={__('Connection')}
            allowClear
            showSearch
            optionFilterProp="label"
            loading={isConnectionsLoading}
            value={query.connection_id}
            options={connectionOptions}
            style={{ width: FILTER_CONTROL_WIDTH }}
            onChange={value => setFilterParam('connection_id', value)}
          />
          <Select
            aria-label={__('Source')}
            placeholder={__('Source')}
            allowClear
            showSearch
            optionFilterProp="label"
            loading={isSourcesLoading}
            value={query.source_plugin}
            options={sourceOptions}
            style={{ width: FILTER_CONTROL_WIDTH }}
            onChange={value => setFilterParam('source_plugin', value)}
          />
          <RangePicker
            placeholder={[__('From date'), __('To date')]}
            allowClear
            value={dateRangeValue}
            onChange={handleDateRangeChange}
            style={{ width: FILTER_CONTROL_WIDTH * 1.6 }}
          />
        </Flex>

        {activeChips.length > 0 ? (
          <Flex gap="small" align="center" wrap>
            {activeChips.map(chip => (
              <Tag key={chip.key} closable onClose={() => clearFilter(chip.key)}>
                {chipLabel(chip.key, chip.value, { connectionNameById, sourceLabelByValue })}
              </Tag>
            ))}
            <Button type="link" size="small" onClick={clearAllFilters} style={{ paddingInline: 0 }}>
              {__('Clear all')}
            </Button>
          </Flex>
        ) : null}

        {hasSelected ? (
          <Flex
            align="center"
            justify="space-between"
            gap="middle"
            style={{
              padding: `${token.paddingXS}px ${token.padding}px`,
              background: token.colorFillTertiary,
              borderRadius: token.borderRadius
            }}
          >
            <Text>{`${selectedRowKeys.length} ${__('selected')}`}</Text>
            <Flex align="center" gap="small">
              <Button danger onClick={handleDelete} loading={isLogDeleting}>
                {__('Delete')}
              </Button>
              <Button onClick={handleResend} loading={isResending}>
                {__('Resend')}
              </Button>
              <Button type="link" size="small" onClick={() => setSelectedRowKeys([])}>
                {__('Clear selection')}
              </Button>
            </Flex>
          </Flex>
        ) : null}

        <Table
          rowKey="id"
          size="middle"
          columns={columns}
          rowSelection={rowSelection}
          dataSource={logs}
          onRow={onRowClick}
          loading={isLoading || isLogsFetching}
          pagination={{
            current: query.pageNo,
            total,
            pageSize: query.limit,
            onChange,
            position: ['bottomRight']
          }}
        />
      </Card>
    </Flex>
  )
}
