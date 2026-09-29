/* eslint-disable import/no-relative-parent-imports */

/* eslint-disable no-nested-ternary */
import type React from 'react'
import { useState } from 'react'
import { DOCS } from '@common/docs'
import { __ } from '@common/helpers/i18nwrap'
import request from '@common/helpers/request'
import { Modal as AntModal, Button, Steps } from 'antd'
import changeLogs from '../../changeLogs'
import cls from './TelemetryPopup.module.css'

type TelemetryPopupProps = {
  isTelemetryModalOpen: boolean
  setIsTelemetryModalOpen: (value: boolean) => void
}

/** Modal walking the user through recent changelog entries and the telemetry opt-in/skip choice. */
function TelemetryPopup({ isTelemetryModalOpen, setIsTelemetryModalOpen }: TelemetryPopupProps) {
  const [current, setCurrent] = useState(0)
  const [isDataNoticeShow, setIsDataNoticeShow] = useState(false)

  /** Record telemetry opt-in and close the modal. */
  const handleTelemetryAccess = () => {
    request({ action: 'telemetry/handle-permission', data: { isChecked: true } })
    setIsTelemetryModalOpen(false)
  }

  /** Record telemetry opt-out and close the modal. */
  const handleTelemetryModalSkip = () => {
    setIsTelemetryModalOpen(false)
    request({ action: 'telemetry/handle-permission', data: { isChecked: false } })
  }

  const steps = [
    {
      title: '',
      content: (
        <>
          <span className={cls.improvementsTitle}>{__('New features')}</span>
          <div className={cls.improvements}>
            <ul>
              {changeLogs.improvements.map(item => (
                <li key={item}>{item}</li>
              ))}
            </ul>
          </div>
          <span className={cls.fixedTitle}>{__('Fixed')}</span>
          <div className={cls.fixed}>
            <ul>
              {changeLogs.fixed.map(item => (
                <li key={item}>{item}</li>
              ))}
            </ul>
          </div>
          <div className={cls.telemetryContent}>
            <h3>
              <b>{__('Help us improve Bit SMTP')}</b>
            </h3>
            <span>{__('Share basic, non-sensitive site details to help us make Bit SMTP better.')}</span>
            <button type="button" onClick={() => setIsDataNoticeShow(true)}>
              {__('What do we collect?')}
            </button>
            {isDataNoticeShow && (
              <>
                <br />
                <span>
                  {__(
                    'Your PHP, MySQL, server and WordPress versions, which plugins are active, your site name and address, and your name and email. Nothing sensitive.'
                  )}{' '}
                  <a href={DOCS.termsOfService} target="_blank" rel="noreferrer">
                    {__('Terms and conditions')}
                  </a>
                </span>
              </>
            )}
          </div>
        </>
      )
    }
  ]

  /** Advance to the next onboarding step. */
  const next = () => {
    setCurrent(current + 1)
  }
  const footerBtnStyle: React.CSSProperties = {
    display: 'flex',
    justifyContent: 'space-between',
    marginTop: '30px'
  }

  return (
    <AntModal
      title={
        <div style={{ textAlign: 'center', fontSize: '20px', marginBottom: '20px' }}>
          {__('What’s new')}
        </div>
      }
      open={isTelemetryModalOpen}
      closable={false}
      width="min(450px, 92vw)"
      centered
      className="telemetry-popup"
      footer={null}
    >
      <>
        <Steps current={current} items={steps} />
        <div className={cls.popupContent}>{steps[current].content}</div>
        <div style={footerBtnStyle}>
          {current < steps.length - 1 && (
            <Button type="primary" onClick={() => next()}>
              {__('Next')}
            </Button>
          )}
          <Button className={cls.skipBtn} onClick={handleTelemetryModalSkip}>
            {__('Skip')}
          </Button>
          {current === steps.length - 1 && (
            <Button type="primary" onClick={() => handleTelemetryAccess()}>
              {__('Accept and finish')}
            </Button>
          )}
        </div>
      </>
    </AntModal>
  )
}

export default TelemetryPopup
