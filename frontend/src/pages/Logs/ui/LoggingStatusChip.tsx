import { Link } from 'react-router-dom'
import { __, sprintf } from '@common/helpers/i18nwrap'
import usePreferences from '@pages/Settings/data/usePreferences'
import { Flex, Tag } from 'antd'

/** Settings tab that owns the logging on/off switch and retention window. */
export const LOGGING_SETTINGS_PATH = '/settings?tab=general'

/** Read-only logging status for the Logs toolbar; changes happen only in Settings, linked from here. */
export default function LoggingStatusChip() {
  const { data: preferences } = usePreferences()
  if (!preferences) return null

  const summary = preferences.logging_enabled
    ? sprintf(__('Keeping logs for %d days'), preferences.log_retention_days)
    : __('Logging is off')

  return (
    <Flex align="center" gap="small">
      <Tag color={preferences.logging_enabled ? undefined : 'warning'} style={{ marginInlineEnd: 0 }}>
        {summary}
      </Tag>
      {preferences.logging_enabled ? (
        <Link to={LOGGING_SETTINGS_PATH} aria-label={__('Change logging settings')}>
          {__('Change')}
        </Link>
      ) : (
        <Link to={LOGGING_SETTINGS_PATH}>{__('Turn on in Settings')}</Link>
      )}
    </Flex>
  )
}
