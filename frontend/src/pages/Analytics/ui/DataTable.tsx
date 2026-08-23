import { Table, type TableColumnsType } from 'antd'

interface DataTableProps<T> {
  columns: TableColumnsType<T>
  rows: Array<T>
  rowKey: keyof T
}

/** The accessible table twin every chart ships alongside its visual - same data, screen-reader/keyboard reachable. */
export default function DataTable<T extends object>({ columns, rows, rowKey }: DataTableProps<T>) {
  return (
    <Table<T>
      size="small"
      pagination={false}
      columns={columns}
      dataSource={rows}
      rowKey={rowKey as string}
      scroll={{ y: 280 }}
    />
  )
}
