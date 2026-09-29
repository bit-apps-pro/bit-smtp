import { type ReactNode, useState } from 'react'
import { TableOutlined } from '@ant-design/icons'
import { __, sprintf } from '@common/helpers/i18nwrap'
import { type HelpDetails, HelpPopover } from '@components/FieldHelp'
import { Button, Card, Flex, Typography, theme } from 'antd'

const { Title } = Typography

interface ChartCardProps {
  title: string
  subtitle?: string
  /** Legend explaining the chart's values, opened from a ⓘ next to the title. */
  help?: HelpDetails
  /** The WCAG-clean table twin of the chart - every chart ships one, per the dataviz accessibility rule. */
  tableView: ReactNode
  children: ReactNode
}

/** Chart container: title, a table-view toggle (the accessibility fallback), and the chart or its table twin. */
export default function ChartCard({ title, subtitle, help, tableView, children }: ChartCardProps) {
  const { token } = theme.useToken()
  const [showTable, setShowTable] = useState(false)

  return (
    <Card styles={{ body: { display: 'flex', flexDirection: 'column', gap: token.padding } }}>
      <Flex justify="space-between" align="flex-start" gap="middle">
        <Flex vertical gap={token.marginXXS}>
          <Flex align="center" gap={2}>
            <Title level={5} style={{ margin: 0 }}>
              {title}
            </Title>
            {help ? <HelpPopover label={sprintf(__('About %s'), title)} details={help} /> : null}
          </Flex>
          {subtitle ? (
            <Typography.Text type="secondary" style={{ fontSize: token.fontSizeSM }}>
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
