import { useEffect, useRef, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { __ } from '@common/helpers/i18nwrap'
import useDeleteLog from '@pages/Logs/data/useDeleteLog'
import { type LogQueryType, type LogType } from '@pages/Logs/data/useFetchLogs'
import useFetchLogs from '@pages/Logs/data/useFetchLogs'
import useResendLogs from '@pages/Logs/data/useResendLogs'
import {
  Badge,
  Button,
  Flex,
  Input,
  Table,
  type TableColumnsType,
  type TableProps,
  Typography,
  notification,
  theme
} from 'antd'
import LogRetentionSettings from './LogRetentionSettings'
import LogToggle from './LogToggle'

const { Title, Text } = Typography

type TableRowSelection<T extends object = object> = TableProps<T>['rowSelection']

const columns: TableColumnsType<LogType> = [
  {
    title: __('Status'),
    dataIndex: 'status',
    key: 'status',
    render: status => <Badge status={status ? 'success' : 'error'} text={status ? 'Sent' : 'Failed'} />
  },
  {
    title: __('Connection'),
    dataIndex: 'connection',
    key: 'connection',
    render: connection => connection || '—'
  },
  { title: __('Subject'), dataIndex: 'subject', key: 'subject' },
  { title: __('To'), dataIndex: 'to_addr', key: 'to_addr' },
  { title: __('Retry'), dataIndex: 'retry_count', key: 'retry_count' },
  { title: __('Sent At'), dataIndex: 'created_at', key: 'created_at' }
]

export default function Logs() {
  const { token } = theme.useToken()
  const [query, setQuery] = useState<LogQueryType>({
    pageNo: 1,
    limit: 20
  } as LogQueryType)
  const { isLoading, isLogsFetching, logs, total, refetch } = useFetchLogs(query)
  const { isLogDeleting, deleteLog } = useDeleteLog()
  const { isResending, resendLogs } = useResendLogs()
  const [selectedRowKeys, setSelectedRowKeys] = useState<React.Key[]>([])
  const navigate = useNavigate()

  const [toAddrInput, setToAddrInput] = useState('')
  // const [dateRange, setDateRange] = useState<[Dayjs, Dayjs] | null>(null)
  const debounceRef = useRef<number | null>(null)

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
      setQuery(prev => ({ ...prev, pageNo: 1, to_addr: value || undefined }))
    }, 500) as unknown as number
  }

  /*  const handleDateRangeChange = (dates: RangePickerOnChangeDate, dateStrings: [string, string]) => {
    const [from, to] = dateStrings
    setDateRange(dates as unknown as [Dayjs, Dayjs] | null)
    setQuery(prev => ({ ...prev, pageNo: 1, date_from: from || undefined, date_to: to || undefined }))
  }

  const clearFilters = () => {
    setToAddrInput('')
    setDateRange(null)
    setQuery(prev => ({
      ...prev,
      pageNo: 1,
      to_addr: undefined,
      date_from: undefined,
      date_to: undefined
    }))
  } */

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
      <Flex align="center" gap="middle" justify="space-between" wrap>
        <Flex gap="small" align="center">
          <Button type="primary" onClick={handleDelete} disabled={!hasSelected} loading={isLogDeleting}>
            {__('Delete')}
          </Button>
          <Button type="primary" onClick={handleResend} disabled={!hasSelected} loading={isResending}>
            {__('Resend')}
          </Button>
          {hasSelected ? <Text type="secondary">Selected {selectedRowKeys.length} items</Text> : null}
        </Flex>
        <Flex align="center" gap="small">
          <Input.Search
            placeholder={__('Search To address')}
            value={toAddrInput}
            onChange={handleToAddrChange}
            allowClear
            style={{ width: 220 }}
            enterButton={false}
          />
          {/* date range filter
          <DatePicker.RangePicker value={dateRange} onChange={handleDateRangeChange} />
          <Button onClick={clearFilters}>{__('Clear')}</Button>
          */}

          <LogToggle />
          <LogRetentionSettings />
        </Flex>
      </Flex>
      <Table
        rowKey="id"
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
          position: ['bottomRight', 'topLeft']
        }}
      />
    </Flex>
  )
}
