import request, { type Response } from '@common/helpers/request'
import { useMutation } from '@tanstack/react-query'

export default function useResendLogs() {
  const { mutateAsync, isPending } = useMutation<Response<unknown>, Error, Array<number>>({
    mutationFn: ids => request({ action: 'mail/resend', data: { ids } })
  })

  return {
    resendLogs: (ids: Array<number>) => mutateAsync(ids),
    isResending: isPending
  }
}
