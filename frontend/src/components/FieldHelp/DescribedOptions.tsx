import { type ReactNode, useId } from 'react'
import { Checkbox, Flex, Radio, type RadioChangeEvent, Typography, theme } from 'antd'

const { Text } = Typography

const DESCRIPTION_INDENT = 24

export type OptionValue = string | number

/** One selectable mode/class with the sentence that explains it. */
export interface DescribedOption<V extends OptionValue = string> {
  value: V
  label: ReactNode
  description?: ReactNode
  disabled?: boolean
  /** Why this option is unavailable; shown under it while disabled. */
  disabledReason?: ReactNode
}

interface CommonProps<V extends OptionValue> {
  options: ReadonlyArray<DescribedOption<V>>
  /** Disables every option (e.g. the parent feature is off). */
  disabled?: boolean
  /** Why the whole group is unavailable; shown under it while disabled. */
  disabledReason?: ReactNode
  id?: string
  'aria-label'?: string
}

export interface CheckboxOptionsProps<V extends OptionValue> extends CommonProps<V> {
  type: 'checkbox'
  value?: V[]
  onChange?: (value: V[]) => void
}

export interface RadioOptionsProps<V extends OptionValue> extends CommonProps<V> {
  type: 'radio'
  value?: V
  onChange?: (value: V) => void
}

export type DescribedOptionsProps<V extends OptionValue = string> =
  | CheckboxOptionsProps<V>
  | RadioOptionsProps<V>

/** The muted line under an option: its disabled reason while disabled, otherwise its description. */
function optionNote<V extends OptionValue>(option: DescribedOption<V>, groupDisabled: boolean) {
  const disabled = groupDisabled || option.disabled
  return disabled && option.disabledReason ? option.disabledReason : option.description
}

/** Renders each option's control (checkbox or radio) with its description linked via aria-describedby; disabled labels stay readable (AA) instead of antd's faint disabled text. */
function OptionList<V extends OptionValue>({
  idPrefix,
  options,
  groupDisabled,
  Control
}: {
  idPrefix: string
  options: ReadonlyArray<DescribedOption<V>>
  groupDisabled: boolean
  Control: typeof Checkbox | typeof Radio
}) {
  const { token } = theme.useToken()

  return options.map((option, index) => {
    const note = optionNote(option, groupDisabled)
    const noteId = `${idPrefix}-${index}`
    const disabled = groupDisabled || option.disabled
    return (
      <Flex key={String(option.value)} vertical>
        <Control value={option.value} disabled={disabled} aria-describedby={note ? noteId : undefined}>
          {disabled ? (
            <span style={{ color: token.colorTextSecondary }}>{option.label}</span>
          ) : (
            option.label
          )}
        </Control>
        {note && (
          <Text id={noteId} type="secondary" style={{ paddingInlineStart: DESCRIPTION_INDENT }}>
            {note}
          </Text>
        )}
      </Flex>
    )
  })
}

/** Controlled checkbox/radio group where every option carries a visible description; Form.Item-compatible. */
export default function DescribedOptions<V extends OptionValue = string>(
  props: DescribedOptionsProps<V>
) {
  const idPrefix = useId()
  const groupNoteId = `${idPrefix}-group`
  const { options, disabled = false, disabledReason, id, 'aria-label': ariaLabel } = props
  const showGroupNote = disabled && Boolean(disabledReason)
  const list = (
    <Flex vertical gap={8}>
      <OptionList
        idPrefix={idPrefix}
        options={options}
        groupDisabled={disabled}
        Control={props.type === 'checkbox' ? Checkbox : Radio}
      />
    </Flex>
  )

  return (
    <Flex
      vertical
      gap={8}
      id={id}
      role={props.type === 'checkbox' ? 'group' : 'radiogroup'}
      aria-label={ariaLabel}
      aria-describedby={showGroupNote ? groupNoteId : undefined}
    >
      {props.type === 'checkbox' ? (
        <Checkbox.Group<V> value={props.value} onChange={props.onChange} disabled={disabled}>
          {list}
        </Checkbox.Group>
      ) : (
        <Radio.Group
          value={props.value}
          onChange={(event: RadioChangeEvent) => props.onChange?.(event.target.value as V)}
          disabled={disabled}
        >
          {list}
        </Radio.Group>
      )}
      {showGroupNote && (
        <Text type="secondary" id={groupNoteId}>
          {disabledReason}
        </Text>
      )}
    </Flex>
  )
}
