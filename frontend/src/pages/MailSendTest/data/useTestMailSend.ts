import { __ } from '@common/helpers/i18nwrap'
import request, { type Response } from '@common/helpers/request'
import notify from '@components/Toaster/Toaster'
import { useMutation } from '@tanstack/react-query'

export default function useTestMailSend() {
  return useMutation<Response<string[]>, Error, Record<string, string>>({
    mutationFn: async values => {
      const data = new FormData()
      Object.keys(values).forEach(key => {
        if (values[key]) {
          data.append(key, values[key])
        }
      })
      return request<string[]>({ action: 'mail/send-test', data })
    },
    onError: () => {
      notify.error(__('Mail send testing failed'))
    },
    onSuccess: res => {
      if (res.code === 'SUCCESS') {
        notify.success(res.message || __('Test mail sent successfully'))
      } else {
        notify.error(res.message || __('Mail send testing failed'))
      }
    }
  })
}
