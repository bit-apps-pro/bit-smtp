import { type ChangeEvent, useRef } from 'react'
import { __ } from '@common/helpers/i18nwrap'
import notify from '@components/Toaster/Toaster'
import { useExportPreferences, useImportPreferences } from '@pages/Settings/data/usePreferences'
import { Button, Card, Flex, Form, Switch, Typography } from 'antd'
import { Download, Upload } from 'lucide-react'

const { Text } = Typography
const EXPORT_FILENAME = 'bit-smtp-preferences.json'

/** Trigger a browser download of the given JSON-serializable payload. */
function downloadJson(filename: string, payload: unknown): void {
  const blob = new Blob([JSON.stringify(payload, null, 2)], { type: 'application/json' })
  const url = URL.createObjectURL(blob)
  const link = document.createElement('a')
  link.href = url
  link.download = filename
  link.click()
  URL.revokeObjectURL(url)
}

/** Parse a File as a plain JSON object, rejecting arrays/primitives/malformed text. */
function readJsonFile(file: File): Promise<Record<string, unknown>> {
  return new Promise((resolve, reject) => {
    const reader = new FileReader()
    reader.onload = () => {
      try {
        const parsed: unknown = JSON.parse(String(reader.result))
        if (typeof parsed !== 'object' || parsed === null || Array.isArray(parsed)) {
          throw new Error('Not a JSON object')
        }
        resolve(parsed as Record<string, unknown>)
      } catch {
        reject(new Error('Invalid preferences file'))
      }
    }
    reader.onerror = () => reject(new Error('Could not read file'))
    reader.readAsText(file)
  })
}

/** Privacy & Data preference fields, plus Export/Import buttons for the whole preferences blob. */
export default function PrivacyData() {
  const fileInputRef = useRef<HTMLInputElement>(null)
  const exportPreferences = useExportPreferences()
  const importPreferences = useImportPreferences()

  const handleExport = () => {
    exportPreferences.mutate(undefined, {
      onSuccess: preferences => {
        downloadJson(EXPORT_FILENAME, { preferences })
        notify.success(__('Preferences exported'))
      },
      onError: () => notify.error(__('Failed to export preferences'))
    })
  }

  const handleImportClick = () => fileInputRef.current?.click()

  const handleFileChange = async (event: ChangeEvent<HTMLInputElement>) => {
    const input = event.target
    const file = input.files?.[0]
    input.value = '' // allow re-selecting the same file next time
    if (!file) {
      return
    }

    try {
      const payload = await readJsonFile(file)
      importPreferences.mutate(payload, {
        onSuccess: response => {
          if (response.status === 'success') {
            notify.success(__('Preferences imported'))
          } else {
            notify.error(response.message || __('Failed to import preferences'))
          }
        },
        onError: () => notify.error(__('Failed to import preferences'))
      })
    } catch {
      notify.error(__('Invalid preferences file'))
    }
  }

  return (
    <Card title={__('Privacy & Data')}>
      <Form.Item
        name="uninstall_purge"
        label={__('Purge data on uninstall')}
        valuePropName="checked"
        extra={
          <Text type="secondary">
            {__('Delete all plugin settings and logs when the plugin is uninstalled.')}
          </Text>
        }
      >
        <Switch />
      </Form.Item>
      <Form.Item
        name="tracking_enabled"
        label={__('Enable open/click tracking')}
        valuePropName="checked"
        extra={
          <Text type="secondary">
            {__('Track email opens and link clicks by adding a tracking pixel and redirect links.')}
          </Text>
        }
      >
        <Switch />
      </Form.Item>
      <Flex gap="small" wrap>
        <Button
          icon={<Download size={16} />}
          onClick={handleExport}
          loading={exportPreferences.isPending}
        >
          {__('Export preferences')}
        </Button>
        <Button
          icon={<Upload size={16} />}
          onClick={handleImportClick}
          loading={importPreferences.isPending}
        >
          {__('Import preferences')}
        </Button>
        <input
          ref={fileInputRef}
          type="file"
          accept="application/json"
          style={{ display: 'none' }}
          onChange={handleFileChange}
          aria-label={__('Import preferences file')}
        />
      </Flex>
    </Card>
  )
}
