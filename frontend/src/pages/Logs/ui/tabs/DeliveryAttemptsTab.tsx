import { CheckCircleTwoTone, CloseCircleTwoTone } from '@ant-design/icons'
import { __ } from '@common/helpers/i18nwrap'
import { type LogAttempt, type LogType } from '@pages/Logs/data/useFetchLogs'
import { Timeline, Typography } from 'antd'

const { Text } = Typography

interface DeliveryAttemptsTabProps {
  log: LogType
}

export default function DeliveryAttemptsTab({ log }: DeliveryAttemptsTabProps) {
  const attempts = log?.details?.attempts
  if (!attempts || attempts.length === 0) {
    return <Text>{__('No delivery attempts recorded')}</Text>
  }

  const items = attempts.map((attempt: LogAttempt) => {
    const isSuccess = attempt.status === 'sent' || attempt.status === 'accepted'
    const icon = isSuccess ? (
      <CheckCircleTwoTone twoToneColor="#52c41a" />
    ) : (
      <CloseCircleTwoTone twoToneColor="#ff4d4f" />
    )

    const statusLabel =
      {
        sent: __('Sent'),
        accepted: __('Accepted'),
        failed: __('Failed')
      }[attempt.status] || attempt.status

    return {
      dot: icon,
      children: (
        <div>
          <Text strong>{attempt.connection}</Text>
          <Text type="secondary" style={{ marginLeft: '0.5em' }}>
            {statusLabel}
          </Text>
          {attempt.error && (
            <div style={{ marginTop: '0.25em' }}>
              <Text type="danger" style={{ fontSize: '0.9em' }}>
                {attempt.error}
              </Text>
            </div>
          )}
        </div>
      )
    }
  })

  return <Timeline items={items} />
}
