import request, { type Response } from '@common/helpers/request'
import { useMutation } from '@tanstack/react-query'

export default function useDeleteLog() {
  const { mutateAsync, isPending } = useMutation<Response<unknown>, Error, number[]>({
    mutationFn: ids =>
      request({
        action: 'logs/delete',
        data: { ids }
      })
  })

  return {
    deleteLog: (ids: number[]) => mutateAsync(ids),
    isLogDeleting: isPending
  }
}
