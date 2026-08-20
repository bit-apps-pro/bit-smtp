import request, { type Response } from '@common/helpers/request'
import { useMutation } from '@tanstack/react-query'

export default function useResendLog() {
  const { mutateAsync, isPending } = useMutation<Response<unknown>, Error, number>({
    mutationFn: id => request({ action: 'mail/resend', data: { ids: [id] } })
  })

  return {
    resendLog: (id: number) => mutateAsync(id),
    isResending: isPending
  }
}
