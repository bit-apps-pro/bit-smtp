import { CloseOutlined } from '@ant-design/icons'
import { DOCS } from '@common/docs'
import { __ } from '@common/helpers/i18nwrap'
import { IconButton, NewTabLink } from '@components/FieldHelp'
import { Alert } from 'antd'
import { useOnboardingFlags } from './onboardingStorage'

/** Dismissible review request, shown only after mail has been proven to work. */
export default function ReviewPrompt() {
  const [flags, updateFlags] = useOnboardingFlags()

  if (flags.reviewPromptDismissed) {
    return null
  }

  const dismiss = () => updateFlags({ reviewPromptDismissed: true })

  return (
    <Alert
      type="info"
      action={
        <IconButton
          type="text"
          size="small"
          icon={<CloseOutlined />}
          label={__('Hide review request')}
          onClick={dismiss}
        />
      }
      message={__('Is Bit SMTP working well for you?')}
      description={
        <>
          {__('A short review helps other site owners find it.')}{' '}
          <NewTabLink href={DOCS.pluginReviews} onClick={dismiss}>
            {__('Leave a review')}
          </NewTabLink>
        </>
      }
    />
  )
}
