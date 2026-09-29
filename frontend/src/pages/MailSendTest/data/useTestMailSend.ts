import { __ } from '@common/helpers/i18nwrap'
import request, { type Response } from '@common/helpers/request'
import notify from '@components/Toaster/Toaster'
import { useMutation } from '@tanstack/react-query'

/** One connection tried during the test send. */
export interface TestSendAttempt {
  connection: string
  status: 'sent' | 'accepted' | 'failed'
  error: string | null
}

/** What the server reports about a test send: every attempt, who sent it, and transport debug lines. */
export interface TestSendReport {
  attempts: TestSendAttempt[]
  debug: string[]
  sent_via: string | null
  failed_over: boolean
}

/** Test form values; an empty `connection_id` means "use routing and backups". */
export interface TestMailValues {
  to: string
  subject: string
  message?: string
  connection_id?: string
}

/** A test-send response whose data is always a well-formed report. */
export type TestSendResponse = Omit<Response<TestSendReport>, 'data'> & { data: TestSendReport }

const EMPTY_REPORT: TestSendReport = { attempts: [], debug: [], sent_via: null, failed_over: false }

/** Coerce the response data into a report; early rejections (e.g. unavailable connection) carry none. */
export function toTestSendReport(data: unknown): TestSendReport {
  if (typeof data !== 'object' || data === null || Array.isArray(data)) {
    return EMPTY_REPORT
  }
  const report = data as Partial<TestSendReport>
  return {
    attempts: Array.isArray(report.attempts) ? report.attempts : [],
    debug: Array.isArray(report.debug) ? report.debug : [],
    sent_via: typeof report.sent_via === 'string' ? report.sent_via : null,
    failed_over: report.failed_over === true
  }
}

/** Build the multipart body, leaving out blank optional fields. */
function toFormData(values: TestMailValues): FormData {
  const data = new FormData()
  Object.entries(values).forEach(([key, value]) => {
    if (typeof value === 'string' && value.trim() !== '') {
      data.append(key, value)
    }
  })
  return data
}

/** Send a test email and return the server's delivery report. */
export default function useTestMailSend() {
  return useMutation<TestSendResponse, Error, TestMailValues>({
    mutationFn: async values => {
      const response = await request<TestSendReport>({
        action: 'mail/send-test',
        data: toFormData(values)
      })
      return { ...response, data: toTestSendReport(response.data) }
    },
    onError: () => {
      notify.error(__('The test email could not be sent. Check your connection and try again.'))
    }
  })
}
