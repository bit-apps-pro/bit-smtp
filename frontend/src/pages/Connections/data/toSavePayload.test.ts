import { type MailSettings } from '@pages/Connections/types'
import { describe, expect, it } from 'vitest'
import toSavePayload from './toSavePayload'

const base: MailSettings = {
  schema_version: 2,
  enabled: true,
  default_connection_id: 'conn_a',
  fallback_connection_ids: [],
  connections: [],
  features: {}
}

describe('toSavePayload', () => {
  it('drops the read-only alerts delivery status but keeps the revision and editable fields', () => {
    const settings: MailSettings = {
      ...base,
      features: {
        routing: [],
        alerts: {
          enabled: true,
          reminder_minutes: 60,
          channels: [],
          revision: 'rev-1',
          delivery: { cron_disabled: true, overdue: false }
        }
      }
    }

    expect(toSavePayload(settings)).toEqual({
      ...base,
      features: {
        routing: [],
        alerts: { enabled: true, reminder_minutes: 60, channels: [], revision: 'rev-1' }
      }
    })
    expect(settings.features.alerts).toHaveProperty('delivery')
  })

  it('returns settings without alerts metadata unchanged', () => {
    const settings: MailSettings = { ...base, features: { alerts: [] } }

    expect(toSavePayload(settings)).toBe(settings)
  })
})
