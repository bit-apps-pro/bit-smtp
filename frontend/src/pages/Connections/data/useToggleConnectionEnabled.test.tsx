import { type ReactNode } from 'react'
import request from '@common/helpers/request'
import { type MailSettings } from '@pages/Connections/types'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { renderHook, waitFor } from '@testing-library/react'
import { type Mock, describe, expect, it, vi } from 'vitest'
import { MAIL_SETTINGS_QUERY_KEY } from './useMailSettings'
import useToggleConnectionEnabled from './useToggleConnectionEnabled'

vi.mock('@common/helpers/request', () => ({ default: vi.fn() }))

const settings: MailSettings = {
  schema_version: 2,
  enabled: true,
  default_connection_id: 'conn_a',
  fallback_connection_ids: [],
  connections: [
    {
      id: 'conn_a',
      provider: 'other_smtp',
      kind: 'smtp',
      name: 'A',
      enabled: true,
      fromEmail: '',
      fromName: '',
      replyToEmail: '',
      settings: {},
      credentials: {}
    },
    {
      id: 'conn_b',
      provider: 'other_smtp',
      kind: 'smtp',
      name: 'B',
      enabled: true,
      fromEmail: '',
      fromName: '',
      replyToEmail: '',
      settings: {},
      credentials: {}
    }
  ],
  features: {}
}

function createWrapper() {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  client.setQueryData(MAIL_SETTINGS_QUERY_KEY, settings)
  const invalidateQueries = vi.spyOn(client, 'invalidateQueries')
  const wrapper = ({ children }: { children: ReactNode }) => (
    <QueryClientProvider client={client}>{children}</QueryClientProvider>
  )
  return { wrapper, invalidateQueries }
}

describe('useToggleConnectionEnabled', () => {
  it('saves the full settings blob with only the target connection flipped, keeping the others', async () => {
    ;(request as Mock).mockResolvedValue({
      status: 'success',
      code: 'SUCCESS',
      message: 'Saved',
      data: 'Saved'
    })

    const { wrapper, invalidateQueries } = createWrapper()
    const { result } = renderHook(() => useToggleConnectionEnabled(), { wrapper })

    result.current.mutate({ id: 'conn_b', enabled: false })

    await waitFor(() => expect(result.current.isSuccess).toBe(true))

    expect(request).toHaveBeenCalledWith({
      action: 'mail/settings/save',
      data: {
        ...settings,
        connections: [settings.connections[0], { ...settings.connections[1], enabled: false }]
      }
    })
    expect(invalidateQueries).toHaveBeenCalledWith({ queryKey: MAIL_SETTINGS_QUERY_KEY })
  })
})
