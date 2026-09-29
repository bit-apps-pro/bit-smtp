import { type MouseEvent, type ReactNode, useState } from 'react'
import { Button, type ButtonProps, Tooltip, type TooltipProps } from 'antd'

export interface IconButtonProps extends Omit<ButtonProps, 'icon' | 'children' | 'aria-label'> {
  icon: ReactNode
  /** Names the button for screen readers and is shown as its tooltip. */
  label: string
  tooltipPlacement?: TooltipProps['placement']
}

/** Icon-only button whose label is both its aria-label and its hover/focus tooltip; clicking dismisses the tooltip. */
export default function IconButton({
  icon,
  label,
  tooltipPlacement,
  onClick,
  ...buttonProps
}: IconButtonProps) {
  const [tooltipOpen, setTooltipOpen] = useState(false)

  /** Hides the tooltip so it doesn't linger over the result while the button keeps focus. */
  const handleClick = (event: MouseEvent<HTMLElement>) => {
    setTooltipOpen(false)
    onClick?.(event)
  }

  return (
    <Tooltip title={label} placement={tooltipPlacement} open={tooltipOpen} onOpenChange={setTooltipOpen}>
      {/* eslint-disable-next-line react/jsx-props-no-spreading -- thin wrapper forwards every Button prop */}
      <Button {...buttonProps} icon={icon} aria-label={label} onClick={handleClick} />
    </Tooltip>
  )
}
