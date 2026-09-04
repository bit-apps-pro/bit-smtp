import { type ReactNode } from 'react'
import { __ } from '@common/helpers/i18nwrap'
import { type FieldMeta } from '@pages/Connections/types'
import { Form, Typography } from 'antd'

/** Builds linked provider guidance for a form-item tooltip. */
export function getFieldTooltip(field: FieldMeta): ReactNode {
  if (!field.help) {
    return undefined
  }

  return (
    <span>
      {field.help.text}{' '}
      <Typography.Link href={field.help.url} target="_blank" rel="noopener noreferrer">
        {field.help.linkLabel}
      </Typography.Link>
    </span>
  )
}

/** Wraps a provider input with its label, validation, and optional guidance. */
export default function FieldItem({ field, children }: { field: FieldMeta; children: ReactNode }) {
  return (
    <Form.Item
      label={field.label}
      name={field.key}
      tooltip={getFieldTooltip(field)}
      rules={field.required ? [{ required: true, message: __('This field is required') }] : undefined}
    >
      {children}
    </Form.Item>
  )
}
