import { type GlossaryEntry } from '@common/glossary'
import { __, sprintf } from '@common/helpers/i18nwrap'
import { type HelpDetails, HelpPopover } from '@components/FieldHelp'
import { Flex, theme } from 'antd'

/** A term list of glossary entries: each label in bold, followed by its one-line hint. */
function LegendList({ entries }: { entries: ReadonlyArray<GlossaryEntry> }) {
  const { token } = theme.useToken()

  return (
    <dl style={{ margin: 0, display: 'flex', flexDirection: 'column', gap: token.marginXS }}>
      {entries.map(entry => (
        <div key={entry.label}>
          <dt style={{ fontWeight: token.fontWeightStrong }}>{entry.label}</dt>
          <dd style={{ margin: 0, color: token.colorTextSecondary }}>{entry.hint}</dd>
        </div>
      ))}
    </dl>
  )
}

/** HelpPopover details that explain every value in a glossary-backed column or chart. */
export function glossaryLegendDetails(
  title: string,
  entries: ReadonlyArray<GlossaryEntry>
): HelpDetails {
  return { title, body: <LegendList entries={entries} /> }
}

interface LegendTitleProps {
  label: string
  entries: ReadonlyArray<GlossaryEntry>
}

/** A column/section label followed by a ⓘ legend popover, opening below so it never covers the controls above the table. */
export default function LegendTitle({ label, entries }: LegendTitleProps) {
  return (
    <Flex align="center" gap={2}>
      {label}
      <HelpPopover
        label={sprintf(__('What %s values mean'), label)}
        details={glossaryLegendDetails(label, entries)}
        placement="bottomLeft"
      />
    </Flex>
  )
}
