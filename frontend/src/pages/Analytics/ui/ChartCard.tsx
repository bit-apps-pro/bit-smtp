import { type ReactNode, useState } from 'react'
import { TableOutlined } from '@ant-design/icons'
import { __ } from '@common/helpers/i18nwrap'
import { Button, Card, Flex, Typography } from 'antd'

const { Title } = Typography

interface ChartCardProps {
  title: string
  subtitle?: string
  /** The WCAG-clean table twin of the chart - every chart ships one, per the dataviz accessibility rule. */
  tableView: ReactNode
  children: ReactNode
}

/** Chart container: title, a table-view toggle (the accessibility fallback), and the chart or its table twin. */
export default function ChartCard({ title, subtitle, tableView, children }: ChartCardProps) {
  const [showTable, setShowTable] = useState(false)

  return (
    <Card styles={{ body: { display: 'flex', flexDirection: 'column', gap: 12 } }}>
      <Flex justify="space-between" align="flex-start" gap="middle">
        <Flex vertical gap={2}>
          <Title level={5} style={{ margin: 0 }}>
            {title}
          </Title>
          {subtitle ? (
            <Typography.Text type="secondary" style={{ fontSize: 12 }}>
              {subtitle}
            </Typography.Text>
          ) : null}
        </Flex>
        <Button
          size="small"
          type="text"
          icon={<TableOutlined />}
          aria-pressed={showTable}
          onClick={() => setShowTable(value => !value)}
        >
          {showTable ? __('View chart') : __('View as table')}
        </Button>
      </Flex>
      {showTable ? tableView : children}
    </Card>
  )
}
