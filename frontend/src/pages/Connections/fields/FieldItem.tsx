import { type ReactNode } from 'react'
import { __ } from '@common/helpers/i18nwrap'
import { type FieldMeta } from '@pages/Connections/types'
import { Form, Typography } from 'antd'

/** Builds visible, linked provider guidance shown beneath a form field. */
export function getFieldHint(field: FieldMeta): ReactNode {
  if (!field.help) {
    return undefined
  }

  const { text, url, linkLabel } = field.help

  return (
    <span>
      {text}
      {url && linkLabel && (
        <>
          {' '}
          <Typography.Link href={url} target="_blank" rel="noopener noreferrer">
            {linkLabel}
          </Typography.Link>
        </>
      )}
    </span>
  )
}

/** Wraps a provider input with its label, validation, and optional guidance. */
export default function FieldItem({ field, children }: { field: FieldMeta; children: ReactNode }) {
  return (
    <Form.Item
      label={field.label}
      name={field.key}
      extra={getFieldHint(field)}
      rules={field.required ? [{ required: true, message: __('This field is required') }] : undefined}
    >
      {children}
    </Form.Item>
  )
}
