import request, { type Response } from '@common/helpers/request'
import { useMutation } from '@tanstack/react-query'

/** Payload for an edited resend: the log to replay, edited recipients/subject, and the chosen connection. */
export type ResendEditPayload = {
  id: number
  to: Array<string>
  cc?: Array<string>
  bcc?: Array<string>
  subject: string
  connection_id: string
}

export default function useResendEditLog() {
  const { mutateAsync, isPending } = useMutation<Response<unknown>, Error, ResendEditPayload>({
    mutationFn: payload => request({ action: 'mail/resend-edit', data: payload })
  })

  return {
    resendEditLog: (payload: ResendEditPayload) => mutateAsync(payload),
    isResendingEdit: isPending
  }
}
