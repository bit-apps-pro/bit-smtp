import notify from '@components/Toaster/Toaster'
import { type AlertChannel, type MailSettings, type StoredAlertSettings } from '@pages/Connections/types'
import { type NotificationFormValues } from '@pages/Settings/sections/NotificationsChannels'
import { act, renderHook } from '@testing-library/react'
import { Form } from 'antd'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import useAlertsSaveFlow from './useAlertsSaveFlow'

vi.mock('@components/Toaster/Toaster', () => ({
  default: { success: vi.fn(), error: vi.fn() }
}))

type RefetchSettingsArg = Parameters<typeof useAlertsSaveFlow>[0]['refetchSettings']

const emailChannel: AlertChannel = {
  id: 'ch_000000000001',
  type: 'email',
  name: 'Ops email',
  enabled: true,
  settings: { recipients: ['ops@example.org'] }
}

/** Build a MailSettings fixture whose alerts hold the given overrides (channels default to one email channel). */
function settingsWith(overrides: Partial<StoredAlertSettings> = {}): MailSettings {
  return {
    schema_version: 2,
    enabled: true,
    default_connection_id: 'conn_1',
    fallback_connection_ids: [],
    connections: [],
    features: {
      logging: { enabled: true },
      alerts: { enabled: true, reminder_minutes: 60, channels: [emailChannel], ...overrides }
    }
  }
}

interface HarnessProps {
  settings: MailSettings | undefined
  refetchSettings: RefetchSettingsArg
  onAlertsIssue: () => void
}

/** Mount a real Form instance alongside the hook, mirroring how SettingsPage wires them together. */
function useHarness({ settings, refetchSettings, onAlertsIssue }: HarnessProps) {
  const [alertsForm] = Form.useForm<NotificationFormValues>()
  const flow = useAlertsSaveFlow({ alertsForm, settings, refetchSettings, onAlertsIssue })

  return { alertsForm, ...flow }
}

describe('useAlertsSaveFlow', () => {
  beforeEach(() => {
    vi.clearAllMocks()
  })

  it('reseeds the alerts form from a background refetch while it is unedited', () => {
    const seeded = settingsWith({ revision: 'rev-1' })
    const refetched = settingsWith({ revision: 'rev-2', reminder_minutes: 90 })
    const onAlertsIssue = vi.fn()
    const { result, rerender } = renderHook((props: HarnessProps) => useHarness(props), {
      initialProps: { settings: seeded, refetchSettings: vi.fn(), onAlertsIssue }
    })

    rerender({ settings: refetched, refetchSettings: vi.fn(), onAlertsIssue })

    expect(result.current.alertsForm.getFieldsValue(true).reminder_minutes).toBe(90)
  })

  it('keeps an edited alerts form untouched by a background refetch', () => {
    const seeded = settingsWith({ revision: 'rev-1' })
    const refetched = settingsWith({ revision: 'rev-2', reminder_minutes: 90 })
    const onAlertsIssue = vi.fn()
    const { result, rerender } = renderHook((props: HarnessProps) => useHarness(props), {
      initialProps: { settings: seeded, refetchSettings: vi.fn(), onAlertsIssue }
    })

    act(() => result.current.alertsForm.setFieldValue('reminder_minutes', 45))
    rerender({ settings: refetched, refetchSettings: vi.fn(), onAlertsIssue })

    expect(result.current.alertsForm.getFieldsValue(true).reminder_minutes).toBe(45)
  })

  it('reloadAlerts refetches, reseeds the form, and clears an active conflict', async () => {
    const seeded = settingsWith({ revision: 'rev-1' })
    const latest = settingsWith({ revision: 'rev-2', reminder_minutes: 120 })
    const refetchSettings = vi.fn(async () => ({
      data: latest,
      isSuccess: true
    })) as unknown as RefetchSettingsArg
    const onAlertsIssue = vi.fn()
    const { result } = renderHook((props: HarnessProps) => useHarness(props), {
      initialProps: { settings: seeded, refetchSettings, onAlertsIssue }
    })
    act(() =>
      result.current.reportSaveFailure({ status: 'error', code: 'alerts_conflict', message: 'stale' })
    )
    expect(result.current.alertsConflict).toBe('stale')

    await act(async () => {
      await result.current.reloadAlerts()
    })

    expect(refetchSettings).toHaveBeenCalled()
    expect(result.current.alertsConflict).toBeNull()
    expect(result.current.isReloadingAlerts).toBe(false)
    expect(result.current.alertsForm.getFieldsValue(true).reminder_minutes).toBe(120)
  })

  it('reloadAlerts notifies and leaves the conflict standing when the refetch fails', async () => {
    const seeded = settingsWith({ revision: 'rev-1' })
    const refetchSettings = vi.fn(async () => ({
      data: undefined,
      isSuccess: false
    })) as unknown as RefetchSettingsArg
    const onAlertsIssue = vi.fn()
    const { result } = renderHook((props: HarnessProps) => useHarness(props), {
      initialProps: { settings: seeded, refetchSettings, onAlertsIssue }
    })
    act(() =>
      result.current.reportSaveFailure({ status: 'error', code: 'alerts_conflict', message: 'stale' })
    )

    await act(async () => {
      await result.current.reloadAlerts()
    })

    expect(notify.error).toHaveBeenCalledWith('Failed to reload settings')
    expect(result.current.alertsConflict).toBe('stale')
  })

  it('adoptSavedAlerts reseeds from a fresh load and resolves true after a successful save', async () => {
    const seeded = settingsWith({ revision: 'rev-1' })
    const saved = settingsWith({ revision: 'rev-2' })
    const refetchSettings = vi.fn(async () => ({
      data: saved,
      isSuccess: true
    })) as unknown as RefetchSettingsArg
    const onAlertsIssue = vi.fn()
    const { result } = renderHook((props: HarnessProps) => useHarness(props), {
      initialProps: { settings: seeded, refetchSettings, onAlertsIssue }
    })

    let adopted = false
    await act(async () => {
      adopted = await result.current.adoptSavedAlerts()
    })

    expect(adopted).toBe(true)
    expect(refetchSettings).toHaveBeenCalled()
  })

  it('adoptSavedAlerts baselines the just-saved values and resolves false when the reload fails', async () => {
    const seeded = settingsWith({ revision: 'rev-1' })
    const refetchSettings = vi.fn(async () => ({
      data: undefined,
      isSuccess: false
    })) as unknown as RefetchSettingsArg
    const onAlertsIssue = vi.fn()
    const { result } = renderHook((props: HarnessProps) => useHarness(props), {
      initialProps: { settings: seeded, refetchSettings, onAlertsIssue }
    })
    act(() => result.current.alertsForm.setFieldValue('reminder_minutes', 15))

    let adopted = true
    await act(async () => {
      adopted = await result.current.adoptSavedAlerts()
    })

    expect(adopted).toBe(false)
    expect(notify.error).toHaveBeenCalledWith('Failed to reload settings')
  })

  it('reportSaveFailure shows the Reload banner and flags the alerts issue on a 409 conflict', () => {
    const onAlertsIssue = vi.fn()
    const { result } = renderHook((props: HarnessProps) => useHarness(props), {
      initialProps: { settings: settingsWith(), refetchSettings: vi.fn(), onAlertsIssue }
    })

    act(() =>
      result.current.reportSaveFailure({
        status: 'error',
        code: 'alerts_conflict',
        message: 'Changed elsewhere'
      })
    )

    expect(onAlertsIssue).toHaveBeenCalled()
    expect(result.current.alertsConflict).toBe('Changed elsewhere')
    expect(notify.error).not.toHaveBeenCalled()
  })

  it('reportSaveFailure toasts the server message for a non-conflict failure', () => {
    const onAlertsIssue = vi.fn()
    const { result } = renderHook((props: HarnessProps) => useHarness(props), {
      initialProps: { settings: settingsWith(), refetchSettings: vi.fn(), onAlertsIssue }
    })

    act(() =>
      result.current.reportSaveFailure({ status: 'error', code: 'invalid', message: 'Bad payload' })
    )

    expect(onAlertsIssue).not.toHaveBeenCalled()
    expect(result.current.alertsConflict).toBeNull()
    expect(notify.error).toHaveBeenCalledWith('Bad payload')
  })

  it('reportInvalidFields flags the alerts issue only when the alerts form is the culprit', () => {
    const onAlertsIssue = vi.fn()
    const { result } = renderHook((props: HarnessProps) => useHarness(props), {
      initialProps: { settings: settingsWith(), refetchSettings: vi.fn(), onAlertsIssue }
    })

    act(() => result.current.reportInvalidFields(false))
    expect(onAlertsIssue).not.toHaveBeenCalled()

    act(() => result.current.reportInvalidFields(true))
    expect(onAlertsIssue).toHaveBeenCalledTimes(1)

    expect(notify.error).toHaveBeenCalledTimes(2)
  })

  it('buildAlertsPayload merges the current settings, the normalized form values, and the seeded revision', () => {
    const seeded = settingsWith({ revision: 'rev-1' })
    const onAlertsIssue = vi.fn()
    const { result } = renderHook((props: HarnessProps) => useHarness(props), {
      initialProps: { settings: seeded, refetchSettings: vi.fn(), onAlertsIssue }
    })

    const payload = result.current.buildAlertsPayload(seeded)

    expect(payload).toEqual({
      features: {
        logging: { enabled: true },
        alerts: { enabled: true, reminder_minutes: 60, channels: [emailChannel], revision: 'rev-1' }
      }
    })
  })
})
