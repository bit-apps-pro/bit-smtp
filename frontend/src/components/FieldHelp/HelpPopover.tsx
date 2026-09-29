import { type ReactNode, useEffect, useId, useRef, useState } from 'react'
import { InfoCircleOutlined } from '@ant-design/icons'
import { __ } from '@common/helpers/i18nwrap'
import { Button, Flex, Popover, type PopoverProps, Typography } from 'antd'

const { Text, Link } = Typography

const CONTENT_MAX_WIDTH = 320

/** Secondary-depth help content: a title, 1-3 sentences, an optional example and doc link. */
export interface HelpDetails {
  title: string
  body: ReactNode
  example?: ReactNode
  docUrl?: string
}

export interface HelpPopoverProps {
  /** Accessible name for the ⓘ trigger button. */
  label: string
  details: HelpDetails
  placement?: PopoverProps['placement']
}

/** Popover body: title, text, muted example block and an optional "Learn more" link. */
function HelpContent({ id, details }: { id: string; details: HelpDetails }) {
  return (
    <Flex id={id} vertical gap={8} style={{ maxWidth: CONTENT_MAX_WIDTH }}>
      <Text strong>{details.title}</Text>
      <div>{details.body}</div>
      {details.example && (
        <Text type="secondary">
          <Text type="secondary" strong>
            {__('Example')}:
          </Text>{' '}
          {details.example}
        </Text>
      )}
      {details.docUrl && (
        <Link href={details.docUrl} target="_blank" rel="noopener noreferrer">
          {__('Learn more')} ↗
        </Link>
      )}
    </Flex>
  )
}

/** Click-triggered ⓘ button that opens help content; Esc closes it and returns focus to the button. */
export default function HelpPopover({ label, details, placement = 'top' }: HelpPopoverProps) {
  const [open, setOpen] = useState(false)
  const triggerRef = useRef<HTMLButtonElement>(null)
  const contentId = useId()

  useEffect(() => {
    if (!open) return undefined

    /** Closes the popover on Escape and restores focus to the trigger. */
    const closeOnEscape = (event: KeyboardEvent) => {
      if (event.key !== 'Escape') return
      setOpen(false)
      triggerRef.current?.focus()
    }

    document.addEventListener('keydown', closeOnEscape)
    return () => document.removeEventListener('keydown', closeOnEscape)
  }, [open])

  return (
    <Popover
      trigger="click"
      open={open}
      onOpenChange={setOpen}
      placement={placement}
      content={<HelpContent id={contentId} details={details} />}
    >
      <Button
        ref={triggerRef}
        type="text"
        size="small"
        icon={<InfoCircleOutlined />}
        aria-label={label}
        aria-expanded={open}
        aria-controls={open ? contentId : undefined}
      />
    </Popover>
  )
}
