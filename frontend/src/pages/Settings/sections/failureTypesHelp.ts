import { __ } from '@common/helpers/i18nwrap'
import { type HelpDetails } from '@components/FieldHelp'

/** Popover copy explaining how failed emails are sorted and which ones are tried again. */
export default function failureTypesHelp(): HelpDetails {
  return {
    title: __('Why did an email fail?'),
    body: __(
      "We sort each failed email into one of five reasons. Only a busy server or a sending limit clears up on its own, so only those are tried again. If we can't tell what went wrong, we treat it as temporary and try again."
    )
  }
}
