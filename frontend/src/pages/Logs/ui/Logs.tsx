import { useEffect, useMemo, useRef, useState } from 'react'
import { useNavigate, useSearchParams } from 'react-router-dom'
import { DELIVERY_STATUSES, SEND_RESULTS } from '@common/glossary'
import { __ } from '@common/helpers/i18nwrap'
import useMailSettings from '@pages/Connections/data/useMailSettings'
import useDeleteLog from '@pages/Logs/data/useDeleteLog'
import useExportLogs, { type LogExportFilters } from '@pages/Logs/data/useExportLogs'
import { type LogQueryType, type LogType } from '@pages/Logs/data/useFetchLogs'
import useFetchLogs from '@pages/Logs/data/useFetchLogs'
import useResendLogs from '@pages/Logs/data/useResendLogs'
import useMailSources from '@pages/Routing/data/useMailSources'
import {
  type Breakpoint,
  Button,
  Card,
  DatePicker,
  Flex,
  Input,
  Popconfirm,
  Select,
  Table,
  type TableColumnsType,
  type TableProps,
  Tag,
  Tooltip,
  Typography,
  notification,
  theme
} from 'antd'
import dayjs, { type Dayjs } from 'dayjs'
import { Download } from 'lucide-react'
import DeliveryStatusTag, { DELIVERY_STATUS_OPTIONS, deliveryStatusLabel } from './DeliveryStatusTag'
import LegendTitle from './GlossaryLegend'
import LoggingStatusChip from './LoggingStatusChip'
import LogsEmptyState from './LogsEmptyState'
import SendResultTag from './SendResultTag'
import {
  deleteConfirmCopy,
  deleteSuccessMessage,
  resendConfirmCopy,
  resendSuccessMessage,
  retriedMessage
} from './logActionCopy'

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

/** Fluid filter widths: grow to fill each wrapped row, stack full-width on phones. */
const FILTER_CONTROL_STYLE = { flex: '1 1 160px', minWidth: 0 }
const DATE_RANGE_STYLE = { flex: '2 1 280px', minWidth: 0 }
const MAX_CHIP_ID_LENGTH = 12

/** Send-result filter options; the backend maps these to its internal success/error log status. */
const SEND_STATUS_OPTIONS = [
  { value: 'sent', label: SEND_RESULTS.sent.label },
  { value: 'failed', label: SEND_RESULTS.failed.label }
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
      return `${__('Result')}: ${SEND_STATUS_LABEL[value] ?? value}`
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

/** Delivery cell: dashed for failed sends, "Not tracked" without delivery tracking, else the reported status. */
function renderDelivery(record: LogType) {
  if (!Number(record.status)) return <Text type="secondary">—</Text>
  return <DeliveryStatusTag status={record.delivery_verified ? record.delivery_status : 'not_tracked'} />
}

/** Retries cell: dashed when never retried, else the count with an explanatory tooltip. */
function renderRetries(retryCount: number) {
  if (!retryCount) return <Text type="secondary">—</Text>
  return (
    <Tooltip title={retriedMessage(retryCount)}>
      <span>{retryCount}</span>
    </Tooltip>
  )
}

/**
 * Minimum viewport breakpoint at which each lower-priority column appears, keyed by column key;
 * an unlisted column always shows. antd breakpoints: xs <576, sm ≥576, md ≥768, lg ≥992, xl ≥1200, xxl ≥1600.
 */
const COLUMN_BREAKPOINTS: Record<string, Breakpoint[]> = {
  retry_count: ['xxl'],
  to_addr: ['xxl'],
  connection: ['xl'],
  subject: ['md']
}

/** Narrowest the table may shrink to before it scrolls inside its card instead of crushing Subject. */
const TABLE_MIN_WIDTH = 720

/** Attaches each column's responsive breakpoints so narrow screens keep only the columns that matter most. */
function withBreakpoints(cols: TableColumnsType<LogType>): TableColumnsType<LogType> {
  return cols.map(column => ({ ...column, responsive: COLUMN_BREAKPOINTS[String(column.key)] }))
}

const columns: TableColumnsType<LogType> = [
  {
    title: <LegendTitle label={__('Result')} entries={Object.values(SEND_RESULTS)} />,
    key: 'result',
    width: 120,
    render: (_, record) => <SendResultTag log={record} />
  },
  {
    title: <LegendTitle label={__('Delivery')} entries={Object.values(DELIVERY_STATUSES)} />,
    key: 'delivery',
    width: 150,
    render: (_, record) => renderDelivery(record)
  },
  {
    title: __('Connection'),
    dataIndex: 'connection',
    key: 'connection',
    width: 160,
    ellipsis: true,
    render: (connection: string | null) => connection || '—'
  },
  { title: __('Subject'), dataIndex: 'subject', key: 'subject', ellipsis: true },
  { title: __('To'), dataIndex: 'to_addr', key: 'to_addr', width: 220, ellipsis: true },
  {
    title: __('Retries'),
    dataIndex: 'retry_count',
    key: 'retry_count',
    width: 80,
    render: (retryCount: number) => renderRetries(retryCount)
  },
  { title: __('Sent at'), dataIndex: 'created_at', key: 'created_at', width: 160 }
]

/** Logs list page: filterable, paginated log table with export, confirmed resend/delete, and logging status. */
export default function Logs() {
  const { token } = theme.useToken()
  const [searchParams, setSearchParams] = useSearchParams()
  const [query, setQuery] = useState<LogQueryType>(() => ({
    pageNo: 1,
    limit: 20,
    ...filtersFromSearchParams(searchParams)
  }))
  const { isLoading, isLogsFetching, logs, total, refetch, trackingEnabled } = useFetchLogs(query)
  const { isLogDeleting, deleteLog } = useDeleteLog()
  const { isResending, resendLogs } = useResendLogs()
  const exportLogs = useExportLogs()
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
  const sourceOptions = (mailSources?.seen ?? []).map(source => ({
    value: source.value,
    label: source.label
  }))
  const connectionNameById = labelLookup(connectionOptions)
  const sourceLabelByValue = labelLookup(sourceOptions)

  const tableColumns = useMemo<TableColumnsType<LogType>>(() => {
    if (!trackingEnabled) {
      return withBreakpoints(columns)
    }
    const trackingColumn: TableColumnsType<LogType>[number] = {
      title: __('Tracking'),
      key: 'tracking',
      width: 120,
      render: (_, record) => {
        if (!record.opened && !record.clicked) {
          return <Text type="secondary">—</Text>
        }
        return (
          <>
            {record.opened ? <Tag color="processing">{__('Opened')}</Tag> : null}
            {record.clicked ? <Tag color="success">{__('Clicked')}</Tag> : null}
          </>
        )
      }
    }
    return withBreakpoints([...columns.slice(0, -1), trackingColumn, ...columns.slice(-1)])
  }, [trackingEnabled])

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

  /** Clears one filter chip, plus its debounce state when it's the to_addr filter. */
  const clearFilter = (key: FilterChipKey) => {
    if (key === 'to_addr') {
      cancelToAddrDebounce()
      setToAddrInput('')
    }
    setFilterParam(key, undefined)
  }

  /** Resets all filter chips, query state, and the URL to their empty state. */
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

  /** Deletes the selected log rows and refreshes the table on success. */
  const handleDelete = async () => {
    const count = selectedRowKeys.length
    try {
      const res = await deleteLog(selectedRowKeys as number[])
      if (res.code !== 'SUCCESS') {
        notification.error({ message: res?.message || __('Failed to delete logs') })
        return
      }
      setSelectedRowKeys([])
      refetch()
      notification.success({ message: deleteSuccessMessage(count) })
    } catch {
      notification.error({ message: __('Failed to delete logs') })
    }
  }

  /** Resends the selected log rows as-is and refreshes the table so the new sends appear. */
  const handleResend = async () => {
    const count = selectedRowKeys.length
    try {
      const res = await resendLogs(selectedRowKeys as number[])
      if (res.code !== 'SUCCESS') {
        notification.error({ message: res?.message || __('Failed to resend') })
        return
      }
      setSelectedRowKeys([])
      refetch()
      notification.success({ message: resendSuccessMessage(count), description: res?.message })
    } catch {
      notification.error({ message: __('Failed to resend') })
    }
  }

  /** The active filters (no pagination) the CSV export should cover. */
  const exportFilters: LogExportFilters = {}
  FILTER_CHIP_KEYS.forEach(key => {
    const value = query[key]
    if (value) exportFilters[key] = value
  })

  /** Exports the active filters as CSV, warning when the export was truncated. */
  const handleExport = () => {
    exportLogs.mutate(exportFilters, {
      onSuccess: response => {
        if (response.status !== 'success') {
          notification.error({ message: response.message || __('Failed to export logs') })
          return
        }
        if (response.data.truncated) {
          notification.warning({
            message: __('Export truncated'),
            description: `${__('Only the first')} ${response.data.count} ${__(
              'rows were exported. Narrow your filters to export fewer rows.'
            )}`
          })
        }
      },
      onError: () => notification.error({ message: __('Failed to export logs') })
    })
  }

  /** Syncs the table's row-selection state. */
  const onSelectChange = (newSelectedRowKeys: React.Key[]) => {
    setSelectedRowKeys(newSelectedRowKeys)
  }

  const rowSelection: TableRowSelection<LogType> = {
    selectedRowKeys,
    onChange: onSelectChange
  }

  const hasSelected = selectedRowKeys.length > 0
  const deleteCopy = deleteConfirmCopy(selectedRowKeys.length)
  const resendCopy = resendConfirmCopy(selectedRowKeys.length)
  /** Updates the table's page number and page size in query state. */
  const onChange = (page: number, pageSize: number) => {
    setQuery(prev => ({ ...prev, pageNo: page, limit: pageSize }))
  }

  /** Row click handler: navigates to the log's detail page. */
  const onRowClick = (record: LogType) => ({
    onClick: () => navigate(`/logs/${record.id}`),
    style: { cursor: 'pointer' }
  })

  /** Debounces the to_addr search input before applying it as a filter. */
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
            placeholder={__('Search recipient')}
            aria-label={__('Search recipient')}
            value={toAddrInput}
            onChange={handleToAddrChange}
            allowClear
            style={{ flex: '1 1 220px', maxWidth: 320 }}
            enterButton={false}
          />
          <Flex align="center" gap="small" wrap>
            <Button icon={<Download size={16} />} onClick={handleExport} loading={exportLogs.isPending}>
              {__('Export CSV')}
            </Button>
            <LoggingStatusChip />
          </Flex>
        </Flex>

        <Flex gap="small" wrap>
          <Select
            aria-label={__('Result')}
            placeholder={__('Result')}
            allowClear
            value={query.status}
            options={SEND_STATUS_OPTIONS}
            style={FILTER_CONTROL_STYLE}
            onChange={value => setFilterParam('status', value)}
          />
          <Select
            aria-label={__('Delivery')}
            placeholder={__('Delivery')}
            allowClear
            value={query.delivery_status}
            options={DELIVERY_STATUS_OPTIONS}
            style={FILTER_CONTROL_STYLE}
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
            style={FILTER_CONTROL_STYLE}
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
            style={FILTER_CONTROL_STYLE}
            onChange={value => setFilterParam('source_plugin', value)}
          />
          <RangePicker
            placeholder={[__('From date'), __('To date')]}
            allowClear
            value={dateRangeValue}
            onChange={handleDateRangeChange}
            style={DATE_RANGE_STYLE}
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
              <Popconfirm
                title={deleteCopy.title}
                description={deleteCopy.description}
                okText={deleteCopy.okText}
                okButtonProps={{ danger: true }}
                cancelText={__('Cancel')}
                onConfirm={handleDelete}
              >
                <Button danger loading={isLogDeleting}>
                  {__('Delete')}
                </Button>
              </Popconfirm>
              <Popconfirm
                title={resendCopy.title}
                description={resendCopy.description}
                okText={resendCopy.okText}
                cancelText={__('Cancel')}
                onConfirm={handleResend}
              >
                <Button loading={isResending}>{__('Resend')}</Button>
              </Popconfirm>
              <Button type="link" size="small" onClick={() => setSelectedRowKeys([])}>
                {__('Clear selection')}
              </Button>
            </Flex>
          </Flex>
        ) : null}

        <Table
          rowKey="id"
          size="middle"
          columns={tableColumns}
          rowSelection={rowSelection}
          dataSource={logs}
          scroll={{ x: TABLE_MIN_WIDTH }}
          onRow={onRowClick}
          loading={isLoading || isLogsFetching}
          locale={{
            emptyText: (
              <LogsEmptyState filtered={activeChips.length > 0} onClearFilters={clearAllFilters} />
            )
          }}
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
