import { type ReactNode } from 'react'
import { Button, type ButtonProps, Tooltip, type TooltipProps } from 'antd'

export interface IconButtonProps extends Omit<ButtonProps, 'icon' | 'children' | 'aria-label'> {
  icon: ReactNode
  /** Names the button for screen readers and is shown as its tooltip. */
  label: string
  tooltipPlacement?: TooltipProps['placement']
}

/** Icon-only button whose label is both its aria-label and its hover/focus tooltip. */
export default function IconButton({ icon, label, tooltipPlacement, ...buttonProps }: IconButtonProps) {
  return (
    <Tooltip title={label} placement={tooltipPlacement}>
      {/* eslint-disable-next-line react/jsx-props-no-spreading -- thin wrapper forwards every Button prop */}
      <Button {...buttonProps} icon={icon} aria-label={label} />
    </Tooltip>
  )
}
