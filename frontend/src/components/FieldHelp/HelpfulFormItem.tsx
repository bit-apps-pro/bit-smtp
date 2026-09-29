import { type ReactNode } from 'react'
import { __, sprintf } from '@common/helpers/i18nwrap'
import { Form, type FormItemProps } from 'antd'
import HelpPopover, { type HelpDetails } from './HelpPopover'

const LABEL_STYLE = { display: 'inline-flex', alignItems: 'center', gap: 2 } as const

export interface HelpfulFormItemProps<Values = unknown>
  extends Omit<FormItemProps<Values>, 'label' | 'extra'> {
  label: string
  /** Always-visible one-liner rendered under the control (Form.Item `extra`). */
  hint?: ReactNode
  /** Optional popover depth, rendered as an ⓘ button after the label. */
  details?: HelpDetails
  /** Accessible name for the ⓘ button; defaults to "Help: {label}". */
  helpLabel?: string
}

/** Form.Item that wires a label, an inline hint (as `extra`) and an optional help popover consistently. */
export default function HelpfulFormItem<Values = unknown>({
  label,
  hint,
  details,
  helpLabel,
  ...formItemProps
}: HelpfulFormItemProps<Values>) {
  const labelNode = details ? (
    <span style={LABEL_STYLE}>
      {label}
      <HelpPopover label={helpLabel ?? sprintf(__('Help: %s'), label)} details={details} />
    </span>
  ) : (
    label
  )

  // eslint-disable-next-line react/jsx-props-no-spreading -- thin wrapper forwards every Form.Item prop
  return <Form.Item<Values> {...formItemProps} label={labelNode} extra={hint} />
}
