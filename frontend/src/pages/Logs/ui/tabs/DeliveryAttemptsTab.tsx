import { CheckCircleTwoTone, CloseCircleTwoTone, MinusCircleTwoTone } from '@ant-design/icons'
import { deliveryStatusEntry, sendResultEntry } from '@common/glossary'
import { __ } from '@common/helpers/i18nwrap'
import { type LogAttempt, type LogType } from '@pages/Logs/data/useFetchLogs'
import { Timeline, Typography, theme } from 'antd'

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

/** Glossary label for one connection attempt's outcome. */
function attemptStatusLabel(status: LogAttempt['status']): string {
  return status === 'accepted' ? deliveryStatusEntry(status).label : sendResultEntry(status).label
}

/** Renders the delivery-attempts timeline plus any routing-skipped connections for a log entry. */
export default function DeliveryAttemptsTab({ log }: DeliveryAttemptsTabProps) {
  const { token } = theme.useToken()
  const attempts = log?.details?.attempts ?? []
  const skipped = log?.details?.routing_skipped ?? []

  if (attempts.length === 0 && skipped.length === 0) {
    return <Text>{__('No delivery attempts recorded')}</Text>
  }

  const items = attempts.map((attempt: LogAttempt) => {
    const isSuccess = attempt.status === 'sent' || attempt.status === 'accepted'
    const icon = isSuccess ? (
      <CheckCircleTwoTone twoToneColor={token.colorSuccess} />
    ) : (
      <CloseCircleTwoTone twoToneColor={token.colorError} />
    )
    const statusLabel = attemptStatusLabel(attempt.status)

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
              dot: <MinusCircleTwoTone twoToneColor={token.colorWarning} />,
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
