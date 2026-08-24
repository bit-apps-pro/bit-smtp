import { useNavigate } from 'react-router-dom'
import { __ } from '@common/helpers/i18nwrap'
import { type LogsFilter } from '@pages/Analytics/logsFilter'
import { Button, Flex, Modal, Typography, theme } from 'antd'

const { Text } = Typography

/** One row of the drill-down: an optional series/status dot, its label, and the formatted value. */
export interface PointDetailMetric {
  label: string
  value: string
  color?: string
}

interface PointDetailModalProps {
  open: boolean
  title: string
  metrics: Array<PointDetailMetric>
  onClose: () => void
  /** `logs/all` query params scoping the Logs page to the emails behind this point; omit to hide the button. */
  logsFilter?: LogsFilter
}

/** Per-point drill-down: a modal listing every metric for one clicked chart point or ranking row. */
export default function PointDetailModal({
  open,
  title,
  metrics,
  onClose,
  logsFilter
}: PointDetailModalProps) {
  const { token } = theme.useToken()
  const navigate = useNavigate()

  /** Closes the modal and deep-links to the Logs page pre-filtered to this point's emails. */
  const handleViewInLogs = () => {
    navigate(`/logs?${new URLSearchParams(logsFilter).toString()}`)
    onClose()
  }

  return (
    <Modal title={title} open={open} onCancel={onClose} footer={null} width={420}>
      <Flex vertical gap={token.paddingSM}>
        {metrics.map(metric => (
          <Flex key={metric.label} justify="space-between" align="center" gap={12}>
            <Flex align="center" gap={8}>
              {metric.color ? (
                <span
                  aria-hidden="true"
                  style={{
                    width: 8,
                    height: 8,
                    borderRadius: 4,
                    background: metric.color,
                    flexShrink: 0
                  }}
                />
              ) : null}
              <Text type="secondary" style={{ fontSize: 13 }}>
                {metric.label}
              </Text>
            </Flex>
            <Text strong style={{ fontSize: 13, color: token.colorText }}>
              {metric.value}
            </Text>
          </Flex>
        ))}
        {logsFilter ? (
          <Button type="primary" block onClick={handleViewInLogs}>
            {__('View in logs')}
          </Button>
        ) : null}
      </Flex>
    </Modal>
  )
}
