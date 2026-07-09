import { type ReactNode } from 'react'
import request from '@common/helpers/request'
import { type Connection } from '@pages/Connections/types'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { renderHook, waitFor } from '@testing-library/react'
import { type Mock, describe, expect, it, vi } from 'vitest'
import { MAIL_SETTINGS_QUERY_KEY } from './useMailSettings'
import useSaveConnection from './useSaveConnection'

vi.mock('@common/helpers/request', () => ({ default: vi.fn() }))

const connection: Connection = {
  id: '',
  provider: 'other_smtp',
  kind: 'smtp',
  name: 'My connection',
  enabled: true,
  fromEmail: 'from@example.com',
  fromName: 'From Name',
  replyToEmail: '',
  settings: { host: 'smtp.example.com', port: 587 },
  credentials: { password: { source: 'database', value: '********' } }
}

function createWrapper() {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  const invalidateQueries = vi.spyOn(client, 'invalidateQueries')
  const wrapper = ({ children }: { children: ReactNode }) => (
    <QueryClientProvider client={client}>{children}</QueryClientProvider>
  )
  return { wrapper, invalidateQueries }
}

describe('useSaveConnection', () => {
  it('POSTs the connection to mail/connections/save and invalidates mail-settings', async () => {
    ;(request as Mock).mockResolvedValue({
      status: 'success',
      code: 'SUCCESS',
      message: 'Connection saved',
      data: 'Connection saved'
    })

    const { wrapper, invalidateQueries } = createWrapper()
    const { result } = renderHook(() => useSaveConnection(), { wrapper })

    result.current.mutate(connection)

    await waitFor(() => expect(result.current.isSuccess).toBe(true))

    expect(request).toHaveBeenCalledWith({ action: 'mail/connections/save', data: connection })
    expect(invalidateQueries).toHaveBeenCalledWith({ queryKey: MAIL_SETTINGS_QUERY_KEY })
  })
})
