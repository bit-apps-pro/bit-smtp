import { useState } from 'react'
import config from '@config/config'
import adBanner from '@resource/img/adBanner.png'
import { Modal } from 'antd'
import confetti from 'canvas-confetti'
import cls from './PromoBanner.module.css'

/** Server-provided campaign button (absent or false outside a campaign window). */
interface AdButton {
  title: string
  campaign: string
  alt?: string
  url: string
}

const MODAL_WIDTH = 'min(720px, 92vw)'

/** Build the campaign landing URL with UTM parameters. */
function campaignUrl(ad: AdButton): string {
  const url = new URL(ad.url)
  url.searchParams.set('utm_source', 'bit-smtp')
  url.searchParams.set('utm_medium', 'inside-plugin')
  url.searchParams.set('utm_campaign', ad.campaign)
  return url.toString()
}

/** Seasonal Bit Apps promotion: a button that opens the campaign banner; renders nothing outside a campaign. */
export default function PromoBanner() {
  const [isOpen, setIsOpen] = useState(false)
  const ad = config.AD_BUTTON as AdButton | false | undefined

  if (!ad || !ad.title) {
    return null
  }

  const open = () => {
    setIsOpen(true)
    confetti({
      particleCount: 100,
      spread: 70,
      origin: { y: 0.6 },
      zIndex: 1000,
      disableForReducedMotion: true
    })
  }

  return (
    <div className={cls.promo}>
      <button type="button" onClick={open} className={cls.btn}>
        {ad.title}
        <span className={cls.star} aria-hidden />
        <span className={cls.star} aria-hidden />
        <span className={cls.star} aria-hidden />
        <span className={cls.star} aria-hidden />
      </button>
      <Modal open={isOpen} onCancel={() => setIsOpen(false)} footer={null} centered width={MODAL_WIDTH}>
        <a href={campaignUrl(ad)} target="_blank" rel="noreferrer">
          <img src={adBanner} alt={ad.alt || ad.title} width="100%" />
        </a>
      </Modal>
    </div>
  )
}
