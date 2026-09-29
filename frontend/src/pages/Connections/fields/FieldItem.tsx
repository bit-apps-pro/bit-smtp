import { type ReactNode } from 'react'
import { __ } from '@common/helpers/i18nwrap'
import { HelpfulFormItem, NewTabLink } from '@components/FieldHelp'
import { type FieldMeta } from '@pages/Connections/types'
import { Form } from 'antd'
import conditionHolds from './fieldCondition'

/** Builds the always-visible hint under a provider field: its guidance, doc link and, for an already-saved secret, the storage note. */
export function getFieldHint(field: FieldMeta, hasStoredSecret = false): ReactNode {
  const secretNote =
    field.secret && hasStoredSecret
      ? __('Your saved key is encrypted and hidden. Paste a new one to replace it.')
      : undefined

  if (!field.help && !secretNote) {
    return undefined
  }

  const { text, url, linkLabel } = field.help ?? { text: '' }

  return (
    <span>
      {text}
      {url && linkLabel && (
        <>
          {' '}
          <NewTabLink href={url}>{linkLabel}</NewTabLink>
        </>
      )}
      {secretNote && <span style={{ display: 'block' }}>{secretNote}</span>}
    </span>
  )
}

/** Whether a provider field must be filled right now: always, or while its `requiredWhen` condition holds. */
export function useFieldRequired(field: FieldMeta): boolean {
  const conditionValue: unknown = Form.useWatch(field.requiredWhen?.field ?? field.key, {
    preserve: true
  })

  if (field.required) {
    return true
  }

  return field.requiredWhen ? conditionHolds(conditionValue, field.requiredWhen) : false
}

/** Wraps a provider input with its label, visible hint and (conditional) required rule. */
export default function FieldItem({
  field,
  valuePropName,
  hasStoredSecret = false,
  children
}: {
  field: FieldMeta
  valuePropName?: string
  hasStoredSecret?: boolean
  children: ReactNode
}) {
  const required = useFieldRequired(field)

  return (
    <HelpfulFormItem
      label={field.label}
      name={field.key}
      hint={getFieldHint(field, hasStoredSecret)}
      valuePropName={valuePropName}
      required={required}
      rules={required ? [{ required: true, message: __('This field is required') }] : undefined}
    >
      {children}
    </HelpfulFormItem>
  )
}
