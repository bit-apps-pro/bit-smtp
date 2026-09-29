import { type ReactNode, useId, useState } from 'react'
import { failureClassEntry } from '@common/glossary'
import { __, sprintf } from '@common/helpers/i18nwrap'
import DebugOutput from '@components/DebugOutput/DebugOutput'
import { updateOnboardingFlags } from '@components/Onboarding'
import notify from '@components/Toaster/Toaster'
import { type Connection } from '@pages/Connections/types'
import { Alert, Button, Collapse, Flex, Input, Typography } from 'antd'
import cls from './ConnectionTestButton.module.css'
import useTestConnection, { type ConnectionTestResult } from './data/useTestConnection'

const EMAIL_PATTERN = /^[^\s@]+@[^\s@]+\.[^\s@]+$/

interface ConnectionTestButtonProps {
  /** Validates the form and returns the connection to test, or undefined to abort. */
  prepare: () => Promise<Connection | undefined>
  defaultRecipient: string
  onResult?: (result: ConnectionTestResult | undefined) => void
  /** Another action (e.g. Save) placed on the same row as the test button, pushed to the far end. */
  trailingAction?: ReactNode
}

interface TestFeedback {
  type: 'success' | 'warning' | 'error'
  message: string
}

/** Maps a test-send outcome to an Alert type and a short headline. */
function connectionTestFeedback(result: ConnectionTestResult): TestFeedback {
  const detail = result.delivery?.detail

  switch (result.delivery?.state) {
    case 'delivered':
      return { type: 'success', message: sprintf(__('Delivered to %s.'), result.to) }
    case 'accepted':
      return {
        type: 'success',
        message: sprintf(
          __('Accepted for %s. Check Logs in a minute to see if it was delivered.'),
          result.to
        )
      }
    case 'deferred':
      return { type: 'warning', message: detail || __('Your provider is delaying this email.') }
    case 'blocked':
    case 'bounced':
    case 'spam':
      return { type: 'error', message: detail || __('The test email was not delivered.') }
    default:
      return result.ok
        ? { type: 'success', message: sprintf(__('Test email sent to %s.'), result.to) }
        : { type: 'error', message: failedHeadline(result) }
  }
}

/** Plain-English headline for a failed test: field problems, a known failure type, or a generic fallback. */
function failedHeadline(result: ConnectionTestResult): string {
  if (result.fieldErrors && Object.keys(result.fieldErrors).length > 0) {
    return __('Some details are missing or wrong. Fix the highlighted fields and try again.')
  }

  return result.failureClass
    ? failureClassEntry(result.failureClass).label
    : __("The test email wasn't sent.")
}

/** Toasts the headline of a test-send outcome. */
function notifyTestOutcome(result: ConnectionTestResult) {
  const feedback = connectionTestFeedback(result)
  notify[feedback.type](feedback.message)
}

/** Recipient input plus "Send test email" button (and an optional trailing action); validates first, then hands the result to the parent. */
export default function ConnectionTestButton({
  prepare,
  defaultRecipient,
  onResult,
  trailingAction
}: ConnectionTestButtonProps) {
  const { mutate, isPending } = useTestConnection()
  const [recipient, setRecipient] = useState<string>()
  const [recipientError, setRecipientError] = useState<string>()
  const inputId = useId()
  const errorId = useId()
  const to = (recipient ?? defaultRecipient).trim()

  /** Validates the recipient and the form, then sends the test email. */
  const handleTest = async () => {
    if (!EMAIL_PATTERN.test(to)) {
      setRecipientError(__('Enter the email address that should receive the test.'))
      return
    }
    setRecipientError(undefined)

    const connection = await prepare()
    if (!connection) {
      return
    }

    mutate(
      { connection, to },
      {
        onSuccess: result => {
          if (result.ok) {
            updateOnboardingFlags({ testEmailSent: true })
          }
          notifyTestOutcome(result)
          onResult?.(result)
        },
        onError: () => notify.error(__("The test email wasn't sent."))
      }
    )
  }

  return (
    <Flex vertical gap={4}>
      <div className={cls.bar}>
        <div className={cls.recipient}>
          <label htmlFor={inputId}>{__('Send test to')}</label>
          <Input
            id={inputId}
            className={cls.recipientInput}
            type="email"
            value={recipient ?? defaultRecipient}
            onChange={event => setRecipient(event.target.value)}
            status={recipientError ? 'error' : undefined}
            aria-invalid={Boolean(recipientError)}
            aria-describedby={recipientError ? errorId : undefined}
          />
        </div>
        <div className={cls.actions}>
          <Button onClick={handleTest} loading={isPending}>
            {__('Send test email')}
          </Button>
          {trailingAction}
        </div>
      </div>
      {recipientError ? (
        <Typography.Text id={errorId} type="danger">
          {recipientError}
        </Typography.Text>
      ) : null}
    </Flex>
  )
}

/** Explains what a successful test proves, plus the provider's own caveat when it has one. */
function successDescription(providerLabel: string, testCaveat: string | undefined): string {
  const proven = sprintf(
    __(
      '%s accepted your login details and sender address. It does not prove every recipient will get your emails.'
    ),
    providerLabel
  )

  return testCaveat ? `${proven} ${testCaveat}` : proven
}

/** Validation messages that no visible field can show (e.g. an OAuth account that isn't connected yet). */
function unpinnedErrors(result: ConnectionTestResult, visibleFieldKeys: string[]): string[] {
  return Object.entries(result.fieldErrors ?? {})
    .filter(([key]) => !visibleFieldKeys.includes(key))
    .map(([, message]) => message)
}

/** Next-step copy for a failed test: unpinned validation messages, else the failure type's hint. */
function failureDescription(
  result: ConnectionTestResult,
  visibleFieldKeys: string[]
): string | undefined {
  const unpinned = unpinnedErrors(result, visibleFieldKeys)
  if (unpinned.length > 0) {
    return unpinned.join(' ')
  }

  return result.failureClass ? failureClassEntry(result.failureClass).hint : undefined
}

/** Collapsed "Technical details" with the provider's raw error and the debug log. */
function TechnicalDetails({ result }: { result: ConnectionTestResult }) {
  const log = Array.isArray(result.debug) ? (result.debug as string[]) : []

  if (!result.error && log.length === 0) {
    return null
  }

  return (
    <Collapse
      size="small"
      items={[
        {
          key: 'details',
          label: __('Technical details'),
          children: (
            <>
              {result.error ? <Typography.Paragraph code>{result.error}</Typography.Paragraph> : null}
              {log.length > 0 ? <DebugOutput log={log} /> : null}
            </>
          )
        }
      ]}
    />
  )
}

/** Renders the last test result (plain-English outcome + collapsed raw details), announced to screen readers. */
export function ConnectionTestOutcome({
  result,
  providerLabel,
  testCaveat,
  visibleFieldKeys = []
}: {
  result?: ConnectionTestResult
  providerLabel: string
  testCaveat?: string
  visibleFieldKeys?: string[]
}) {
  if (!result) {
    return null
  }

  const feedback = connectionTestFeedback(result)
  const description =
    feedback.type === 'success'
      ? successDescription(providerLabel, testCaveat)
      : failureDescription(result, visibleFieldKeys)

  return (
    <Flex vertical gap="small" role="status" aria-live="polite">
      <Alert type={feedback.type} message={feedback.message} description={description} showIcon />
      <TechnicalDetails result={result} />
    </Flex>
  )
}
