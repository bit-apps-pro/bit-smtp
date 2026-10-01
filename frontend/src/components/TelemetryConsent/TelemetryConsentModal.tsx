import { useState } from 'react'
import { __ } from '@common/helpers/i18nwrap'
import notify from '@components/Toaster/Toaster'
import { Button, Flex, Modal, Typography, theme } from 'antd'
import SharedDataDetails from './SharedDataDetails'
import { useSaveTelemetryConsent, useTelemetryStatus } from './useTelemetryConsent'

const { Paragraph } = Typography

const MODAL_WIDTH = 520

/** One-time, opt-in question about sharing usage data; shown only until the site owner answers. */
export default function TelemetryConsentModal() {
  const { token } = theme.useToken()
  const { data: status } = useTelemetryStatus()
  const saveConsent = useSaveTelemetryConsent()
  const [closed, setClosed] = useState(false)

  if (closed || status?.answered !== false) {
    return null
  }

  /** Close right away and save the answer; a failed save only keeps it closed for this visit. */
  const answer = (allow: boolean) => {
    setClosed(true)
    saveConsent.mutate(allow, {
      onError: () =>
        notify.error(
          __('Couldn’t confirm your choice'),
          __('Check Settings › Privacy & data to see whether sharing is on.')
        )
    })
  }

  return (
    <Modal
      open
      centered
      width={`min(${MODAL_WIDTH}px, calc(100vw - ${token.marginLG}px))`}
      title={__('Help us make Bit SMTP better?')}
      onCancel={() => answer(false)}
      maskClosable={false}
      footer={
        <Flex gap="small" justify="flex-end" wrap>
          <Button onClick={() => answer(false)}>{__('No thanks')}</Button>
          <Button onClick={() => answer(true)}>{__('Yes, share')}</Button>
        </Flex>
      }
    >
      <Paragraph>
        {__(
          'You can send us some basic facts about your site. It shows us which WordPress and PHP versions to support and what to fix first. Nothing is sent unless you choose “Yes, share”.'
        )}
      </Paragraph>
      <SharedDataDetails />
      <Paragraph type="secondary" style={{ marginTop: token.marginSM, marginBottom: 0 }}>
        {__('You can change this any time in Settings › Privacy & data.')}
      </Paragraph>
    </Modal>
  )
}
