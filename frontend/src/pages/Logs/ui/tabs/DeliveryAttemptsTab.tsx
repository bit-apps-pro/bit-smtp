import { CheckCircleTwoTone, CloseCircleTwoTone, MinusCircleTwoTone } from '@ant-design/icons'
import { __ } from '@common/helpers/i18nwrap'
import { type LogAttempt, type LogType } from '@pages/Logs/data/useFetchLogs'
import { Timeline, Typography } from 'antd'

const { Text } = Typography

interface DeliveryAttemptsTabProps {
  log: LogType
}

/** Map a stable backend skip-reason code ('disabled' | 'deleted' | 'incomplete') to a display label. */
function reasonLabel(reason: string): string {
  switch (reason) {
    case 'disabled':
      return __('Disabled')
    case 'deleted':
      return __('Deleted')
    case 'incomplete':
      return __('Incomplete')
    default:
      return reason
  }
}

export default function DeliveryAttemptsTab({ log }: DeliveryAttemptsTabProps) {
  const attempts = log?.details?.attempts ?? []
  const skipped = log?.details?.routing_skipped ?? []

  if (attempts.length === 0 && skipped.length === 0) {
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

  return (
    <>
      {skipped.length > 0 && (
        <div style={{ marginBottom: '1em' }}>
          <Text strong>{__('Skipped connections')}</Text>
          <Timeline
            style={{ marginTop: '0.5em' }}
            items={skipped.map(skip => ({
              dot: <MinusCircleTwoTone twoToneColor="#faad14" />,
              children: (
                <div>
                  <Text strong>{skip.connection}</Text>
                  <Text type="secondary" style={{ marginLeft: '0.5em' }}>
                    {reasonLabel(skip.reason)}
                  </Text>
                </div>
              )
            }))}
          />
        </div>
      )}
      {items.length > 0 && <Timeline items={items} />}
    </>
  )
}
