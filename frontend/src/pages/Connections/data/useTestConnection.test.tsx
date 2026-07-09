import { type ReactNode } from 'react'
import request from '@common/helpers/request'
import { type Connection } from '@pages/Connections/types'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { renderHook, waitFor } from '@testing-library/react'
import { type Mock, describe, expect, it, vi } from 'vitest'
import useTestConnection from './useTestConnection'

vi.mock('@common/helpers/request', () => ({ default: vi.fn() }))

const connection: Connection = {
  id: 'conn_a',
  provider: 'other_smtp',
  kind: 'smtp',
  name: 'A',
  enabled: true,
  fromEmail: 'from@example.com',
  fromName: 'From',
  replyToEmail: '',
  settings: { host: 'smtp.example.com', port: 587 },
  credentials: { password: { source: 'database', value: '********' } }
}

function wrapper({ children }: { children: ReactNode }) {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  return <QueryClientProvider client={client}>{children}</QueryClientProvider>
}

describe('useTestConnection', () => {
  it('posts {...connection, to} and surfaces ok + debug on success', async () => {
    ;(request as Mock).mockResolvedValue({
      status: 'success',
      code: 'SUCCESS',
      message: undefined,
      data: ['Connected', 'Message sent']
    })

    const { result } = renderHook(() => useTestConnection(), { wrapper })

    result.current.mutate({ connection, to: 'test@example.com' })

    await waitFor(() => expect(result.current.isSuccess).toBe(true))

    expect(request).toHaveBeenCalledWith({
      action: 'mail/connections/test',
      data: { ...connection, to: 'test@example.com' }
    })
    expect(result.current.data).toEqual({
      ok: true,
      debug: ['Connected', 'Message sent'],
      error: undefined
    })
  })

  it('surfaces ok:false + error on failure', async () => {
    ;(request as Mock).mockResolvedValue({
      status: 'error',
      code: 'ERROR',
      message: 'Connection test failed',
      data: ['SMTP connect() failed']
    })

    const { result } = renderHook(() => useTestConnection(), { wrapper })

    result.current.mutate({ connection, to: 'test@example.com' })

    await waitFor(() => expect(result.current.isSuccess).toBe(true))

    expect(result.current.data).toEqual({
      ok: false,
      debug: ['SMTP connect() failed'],
      error: 'Connection test failed'
    })
  })
})
