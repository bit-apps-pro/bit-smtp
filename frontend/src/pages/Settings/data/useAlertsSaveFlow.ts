import { useCallback, useEffect, useRef, useState } from 'react'
import { __ } from '@common/helpers/i18nwrap'
import notify from '@components/Toaster/Toaster'
import { type MailSettings } from '@pages/Connections/types'
import {
  type NotificationFormValues,
  storedAlerts,
  toAlertsFormValues,
  toStoredAlerts
} from '@pages/Settings/sections/NotificationsChannels'
import valuesEqual from '@pages/Settings/utils/valuesEqual'
import { type QueryObserverResult } from '@tanstack/react-query'
import { type FormInstance } from 'antd'

/** Mirrors the backend's stale-revision error code for a concurrent alerts save (HTTP 409). */
const ALERTS_CONFLICT_CODE = 'alerts_conflict'

/** A single store's save outcome, normalized from either mutation's echoed response. */
export interface SaveOutcome {
  status: 'success' | 'error'
  code?: string
  message?: string
}

/** The `refetch` function `useMailSettings` returns, used here to reload after a save or a conflict. */
type RefetchSettings = () => Promise<QueryObserverResult<MailSettings, Error>>

export interface UseAlertsSaveFlowOptions {
  /** The Notifications tab's Form instance. */
  alertsForm: FormInstance<NotificationFormValues>
  /** The latest loaded mail settings, or undefined while still loading. */
  settings: MailSettings | undefined
  /** Refetch the mail settings from the server. */
  refetchSettings: RefetchSettings
  /** Called when the alerts store caused a save or validation failure, so the page can surface it (e.g. switch tabs). */
  onAlertsIssue: () => void
}

export interface UseAlertsSaveFlowResult {
  /** The active conflict banner message, or null when there is none to show. */
  alertsConflict: string | null
  /** Whether a Reload (from the conflict banner) is in flight. */
  isReloadingAlerts: boolean
  /** Discard local alert edits and reseed the form from a fresh load, resolving a save conflict. */
  reloadAlerts: () => Promise<void>
  /** Build a save payload for the mail-settings store from the alerts form, echoing the seeded revision. */
  buildAlertsPayload: (settings: MailSettings) => Partial<MailSettings>
  /** Reseed from a fresh load after a successful save, else baseline the just-saved values; false on failure. */
  adoptSavedAlerts: () => Promise<boolean>
  /** Surface a failed save: a revision conflict gets the Reload banner, anything else the server message. */
  reportSaveFailure: (failed: SaveOutcome) => void
  /** Report a failed save caused by invalid fields, notifying the alerts issue when the Notifications tab is the culprit. */
  reportInvalidFields: (alertsInvalid: boolean) => void
}

/** Owns the Notifications tab's optimistic-concurrency save flow: seeding, background reseed, conflict banner, and reload. */
export default function useAlertsSaveFlow({
  alertsForm,
  settings,
  refetchSettings,
  onAlertsIssue
}: UseAlertsSaveFlowOptions): UseAlertsSaveFlowResult {
  const [alertsConflict, setAlertsConflict] = useState<string | null>(null)
  const [isReloadingAlerts, setIsReloadingAlerts] = useState(false)
  const lastSeededAlerts = useRef<NotificationFormValues | null>(null)
  const lastSeededRevision = useRef<string | undefined>(undefined)

  /** Load `loaded` into the alerts form and remember it (and its revision) as the saved baseline. */
  const seedAlerts = useCallback(
    (loaded: MailSettings) => {
      lastSeededAlerts.current = toAlertsFormValues(loaded)
      lastSeededRevision.current = storedAlerts(loaded)?.revision
      alertsForm.setFieldsValue(lastSeededAlerts.current)
    },
    [alertsForm]
  )

  /** Adopt background-refetched settings only while the alerts form is unedited, so a reseed never wipes unsaved cards. */
  const adoptRefetchedAlerts = useCallback(
    (loaded: MailSettings) => {
      const current = alertsForm.getFieldsValue(true)
      if (!lastSeededAlerts.current || valuesEqual(current, lastSeededAlerts.current)) {
        seedAlerts(loaded)
      }
    },
    [alertsForm, seedAlerts]
  )

  useEffect(() => {
    if (settings) {
      adoptRefetchedAlerts(settings)
    }
  }, [adoptRefetchedAlerts, settings])

  /** Refetch the mail settings and reseed the alerts form (and its revision) from them; false on failure. */
  const refreshAlerts = async (): Promise<boolean> => {
    const { data, isSuccess } = await refetchSettings()
    if (!isSuccess || !data) {
      notify.error(__('Failed to reload settings'))
      return false
    }
    seedAlerts(data)
    return true
  }

  /** Reseed from a fresh load after a successful save, else baseline the just-saved values; false on failure. */
  const adoptSavedAlerts = async (): Promise<boolean> => {
    setAlertsConflict(null)
    if (await refreshAlerts()) {
      return true
    }
    lastSeededAlerts.current = alertsForm.getFieldsValue(true)
    return false
  }

  /** Discard local alert edits and reseed the form from a fresh load, resolving a save conflict. */
  const reloadAlerts = async () => {
    setIsReloadingAlerts(true)
    try {
      if (await refreshAlerts()) {
        setAlertsConflict(null)
      }
    } finally {
      setIsReloadingAlerts(false)
    }
  }

  /** Surface a failed save: a revision conflict gets the Reload banner, anything else the server message. */
  const reportSaveFailure = (failed: SaveOutcome) => {
    if (failed.code === ALERTS_CONFLICT_CODE) {
      onAlertsIssue()
      setAlertsConflict(
        failed.message ||
          __('Notification settings were changed in another tab. Reload to see the latest.')
      )
      return
    }
    notify.error(failed.message || __('Failed to save settings'))
  }

  /** Report a failed save of fields that live on another tab, jumping to Notifications when that's the culprit. */
  const reportInvalidFields = (alertsInvalid: boolean) => {
    if (alertsInvalid) {
      onAlertsIssue()
    }
    notify.error(__('Please fix the highlighted fields before saving.'))
  }

  /** Build a save payload for the mail-settings store from the alerts form, echoing the seeded revision. */
  const buildAlertsPayload = (current: MailSettings): Partial<MailSettings> => ({
    features: {
      ...current.features,
      alerts: {
        ...toStoredAlerts(alertsForm.getFieldsValue(true)),
        revision: lastSeededRevision.current
      }
    }
  })

  return {
    alertsConflict,
    isReloadingAlerts,
    reloadAlerts,
    buildAlertsPayload,
    adoptSavedAlerts,
    reportSaveFailure,
    reportInvalidFields
  }
}
