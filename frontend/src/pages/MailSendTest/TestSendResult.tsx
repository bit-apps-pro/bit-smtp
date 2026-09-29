import { SEND_RESULTS } from '@common/glossary'
import { __, sprintf } from '@common/helpers/i18nwrap'
import { Alert, Flex, Typography } from 'antd'
import { type TestSendAttempt, type TestSendResponse } from './data/useTestMailSend'

const { Text } = Typography

/** List the connections that failed, each with the reason it gave. */
function FailedAttempts({ attempts }: { attempts: TestSendAttempt[] }) {
  const failed = attempts.filter(attempt => attempt.status === 'failed')
  if (failed.length === 0) {
    return null
  }
  return (
    <ul style={{ margin: 0, paddingInlineStart: '1.25em' }}>
      {failed.map((attempt, index) => (
        // eslint-disable-next-line react/no-array-index-key -- the same connection can be tried more than once
        <li key={`${attempt.connection}-${index}`}>
          <Text strong>{attempt.connection}</Text>
          {attempt.error ? `: ${attempt.error}` : ''}
        </li>
      ))}
    </ul>
  )
}

/** Outcome banner for a test send: plain success, success through a backup connection, or failure. */
export default function TestSendResult({ response }: { response: TestSendResponse }) {
  const { attempts, sent_via: sentVia, failed_over: failedOver } = response.data

  if (response.code !== 'SUCCESS') {
    return (
      <Alert
        showIcon
        type="error"
        message={response.message || __('The test email was not sent')}
        description={
          <Flex vertical gap="small">
            <FailedAttempts attempts={attempts} />
            <Text>{__('Fix the connection using the details below, then try again.')}</Text>
          </Flex>
        }
      />
    )
  }

  if (failedOver && sentVia) {
    return (
      <Alert
        showIcon
        type="warning"
        message={SEND_RESULTS.failover_recovered.label}
        description={
          <Flex vertical gap="small">
            <Text>
              {sprintf(
                __('Delivered by %s, one of your backup connections. These failed first:'),
                sentVia
              )}
            </Text>
            <FailedAttempts attempts={attempts} />
            <Text>{SEND_RESULTS.failover_recovered.next}</Text>
          </Flex>
        }
      />
    )
  }

  return (
    <Alert
      showIcon
      type="success"
      message={__('Test email sent')}
      description={
        sentVia
          ? sprintf(__('Sent by %s. Check the inbox to make sure it arrived.'), sentVia)
          : __('Check the inbox to make sure it arrived.')
      }
    />
  )
}
