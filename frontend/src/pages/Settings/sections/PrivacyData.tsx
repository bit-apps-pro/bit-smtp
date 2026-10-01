import { type ChangeEvent, useRef } from 'react'
import { triggerBlobDownload } from '@common/helpers/download'
import { __ } from '@common/helpers/i18nwrap'
import { HelpfulFormItem } from '@components/FieldHelp'
import notify from '@components/Toaster/Toaster'
import SettingsPanel, { PanelDivider } from '@pages/Settings/components/SettingsPanel'
import { useExportPreferences, useImportPreferences } from '@pages/Settings/data/usePreferences'
import { Button, Flex, Form, Popconfirm, Switch, Typography } from 'antd'
import { Download, Upload } from 'lucide-react'
import UsageSharingField from './UsageSharingField'

const { Text } = Typography
const EXPORT_FILENAME = 'bit-smtp-preferences.json'

/** Trigger a browser download of the given JSON-serializable payload. */
function downloadJson(filename: string, payload: unknown): void {
  triggerBlobDownload(
    filename,
    new Blob([JSON.stringify(payload, null, 2)], { type: 'application/json' })
  )
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
  const form = Form.useFormInstance()
  const purgeOnUninstall = Form.useWatch('uninstall_purge', form) ?? false
  const fileInputRef = useRef<HTMLInputElement>(null)
  const exportPreferences = useExportPreferences()
  const importPreferences = useImportPreferences()

  /** Downloads the current preferences as JSON and toasts the outcome. */
  const handleExport = () => {
    exportPreferences.mutate(undefined, {
      onSuccess: preferences => {
        downloadJson(EXPORT_FILENAME, { preferences })
        notify.success(__('Settings exported'))
      },
      onError: () => notify.error(__('Couldn’t export your settings. Please try again.'))
    })
  }

  const handleImportClick = () => fileInputRef.current?.click()

  /** Reads the selected file as preferences JSON and imports it, toasting success/failure. */
  const handleFileChange = async (event: ChangeEvent<HTMLInputElement>) => {
    const input = event.target
    const file = input.files?.[0]
    input.value = ''
    if (!file) {
      return
    }

    try {
      const payload = await readJsonFile(file)
      importPreferences.mutate(payload, {
        onSuccess: response => {
          if (response.status === 'success') {
            notify.success(__('Settings imported'))
          } else {
            notify.error(response.message || __('Couldn’t import that file. Check it and try again.'))
          }
        },
        onError: () => notify.error(__('Couldn’t import that file. Check it and try again.'))
      })
    } catch {
      notify.error(__('That file isn’t a Bit SMTP settings file.'))
    }
  }

  return (
    <SettingsPanel intro={__('Choose what happens to your data, and back up these settings to a file.')}>
      <HelpfulFormItem
        name="uninstall_purge"
        label={__('Delete all data when you delete the plugin')}
        hint={
          <Text type={purgeOnUninstall ? 'danger' : 'secondary'}>
            {__('Removes every connection, password, log and setting for good. This can’t be undone.')}
          </Text>
        }
        valuePropName="checked"
      >
        <Switch />
      </HelpfulFormItem>
      <PanelDivider />
      <HelpfulFormItem
        name="tracking_enabled"
        label={__('Track opens and clicks')}
        hint={__('Shows when people open your emails or click their links. Needs email logs turned on.')}
        details={{
          title: __('What gets tracked?'),
          body: __(
            'HTML emails get a tiny invisible image and tracked links. Some email apps open images by themselves, so those opens are marked separately. You can export or erase this data with the WordPress privacy tools.'
          )
        }}
        valuePropName="checked"
      >
        <Switch />
      </HelpfulFormItem>
      <PanelDivider />
      <UsageSharingField />
      <PanelDivider />
      <Flex gap="small" wrap>
        <Button
          icon={<Download size={16} />}
          onClick={handleExport}
          loading={exportPreferences.isPending}
        >
          {__('Export settings')}
        </Button>
        <Popconfirm
          title={__('Replace your settings?')}
          description={__('Importing a file replaces all your settings right away.')}
          okText={__('Choose file')}
          cancelText={__('Cancel')}
          onConfirm={handleImportClick}
        >
          <Button icon={<Upload size={16} />} loading={importPreferences.isPending}>
            {__('Import settings')}
          </Button>
        </Popconfirm>
        <input
          ref={fileInputRef}
          type="file"
          accept="application/json"
          hidden
          onChange={handleFileChange}
          aria-label={__('Settings file to import')}
        />
      </Flex>
    </SettingsPanel>
  )
}
