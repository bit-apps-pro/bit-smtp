import { useEffect, useState } from 'react'
import { SettingOutlined } from '@ant-design/icons'
import { __ } from '@common/helpers/i18nwrap'
import notify from '@components/Toaster/Toaster'
import usePreferences, { useSavePreferences } from '@pages/Settings/data/usePreferences'
import { Button, Flex, Form, InputNumber, Popover } from 'antd'

const MIN_RETENTION_DAYS = 1
const MAX_RETENTION_DAYS = 200

interface LogRetentionSettingsProps {
  onSuccess?: () => void
}

/** Logs toolbar popover to view and change the saved log retention window (shared with Settings). */
export default function LogRetentionSettings({ onSuccess }: LogRetentionSettingsProps) {
  const { data: preferences, isLoading } = usePreferences()
  const { mutateAsync: savePreferences, isPending } = useSavePreferences()
  const savedDays = preferences?.log_retention_days
  const [retentionDays, setRetentionDays] = useState<number | null>(savedDays ?? null)

  useEffect(() => {
    if (savedDays !== undefined) {
      setRetentionDays(savedDays)
    }
  }, [savedDays])

  /** Validate the entered retention window, then save it and report the outcome. */
  const handleRetentionSave = async () => {
    if (
      retentionDays === null ||
      !Number.isInteger(retentionDays) ||
      retentionDays < MIN_RETENTION_DAYS ||
      retentionDays > MAX_RETENTION_DAYS
    ) {
      notify.error(__('Enter a number of days between 1 and 200'))
      return
    }

    try {
      const response = await savePreferences({ log_retention_days: retentionDays })
      if (response.status === 'success') {
        notify.success(__('Log retention period updated successfully'))
        onSuccess?.()
      } else {
        notify.error(response.message || __('Failed to update log retention period'))
      }
    } catch {
      notify.error(__('Failed to update log retention period'))
    }
  }

  const retentionContent = (
    <Flex vertical gap="small">
      <Form.Item
        label={__('Days')}
        tooltip={__('Number of days to retain logs before automatic deletion.')}
      >
        <InputNumber
          value={retentionDays}
          onChange={setRetentionDays}
          disabled={isLoading}
          placeholder={__('Enter days')}
          style={{ width: 200 }}
        />
      </Form.Item>

      <Button
        type="primary"
        onClick={handleRetentionSave}
        loading={isPending}
        disabled={isLoading || retentionDays === savedDays}
      >
        {__('Save')}
      </Button>
    </Flex>
  )

  return (
    <Popover
      content={retentionContent}
      title={__('Log Retention Settings')}
      trigger="click"
      placement="bottomRight"
    >
      <Button icon={<SettingOutlined />} aria-label={__('Log retention settings')} />
    </Popover>
  )
}
