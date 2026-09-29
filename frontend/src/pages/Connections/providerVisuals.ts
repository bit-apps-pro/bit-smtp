import { __ } from '@common/helpers/i18nwrap'
import { NEUTRAL_ACCENT, PRIMARY_SEED } from '@config/themes/palette'
import amazonSesLogo from '@resource/img/providers/amazon_ses.svg'
import brevoLogo from '@resource/img/providers/brevo.svg'
import gmailLogo from '@resource/img/providers/gmail.svg'
import mailgunLogo from '@resource/img/providers/mailgun.svg'
import mailjetLogo from '@resource/img/providers/mailjet.png'
import microsoft365Logo from '@resource/img/providers/microsoft365.svg'
import otherSmtpLogo from '@resource/img/providers/other_smtp.svg'
import resendLogo from '@resource/img/providers/resend.svg'
import sendgridLogo from '@resource/img/providers/sendgrid.svg'
import sparkpostLogo from '@resource/img/providers/sparkpost.svg'
import zeptomailLogo from '@resource/img/providers/zeptomail.svg'

export interface ProviderVisual {
  logo: string | null
  initial: string
  accent: string
  blurb: string
}

interface ProviderEntry {
  logo: string | null
  accent: string
  /** Deferred so the text is translated at render time, after translations load. */
  blurb: () => string
}

const providers: Record<string, ProviderEntry> = {
  other_smtp: {
    logo: otherSmtpLogo,
    accent: NEUTRAL_ACCENT,
    blurb: () => __('Any SMTP server')
  },
  php_sendmail: {
    logo: null,
    accent: NEUTRAL_ACCENT,
    blurb: () => __('PHP mail() / server sendmail')
  },
  sendgrid: {
    logo: sendgridLogo,
    accent: '#1A82E2',
    blurb: () => __('SendGrid API')
  },
  gmail: {
    logo: gmailLogo,
    accent: '#EA4335',
    blurb: () => __('Gmail / Google Workspace')
  },
  amazon_ses: {
    logo: amazonSesLogo,
    accent: '#FF9900',
    blurb: () => __('Amazon SES API')
  },
  postmark: {
    logo: null,
    accent: '#E8912B',
    blurb: () => __('Postmark API')
  },
  brevo: {
    logo: brevoLogo,
    accent: '#0B996E',
    blurb: () => __('Brevo (Sendinblue) API')
  },
  cloudflare: {
    logo: null,
    accent: '#F6821F',
    blurb: () => __('Cloudflare Email Sender API')
  },
  resend: {
    logo: resendLogo,
    accent: '#0A0A0A',
    blurb: () => __('Resend API')
  },
  mailjet: {
    logo: mailjetLogo,
    accent: '#F5A623',
    blurb: () => __('Mailjet API')
  },
  zeptomail: {
    logo: zeptomailLogo,
    accent: '#E42527',
    blurb: () => __('Zoho ZeptoMail API')
  },
  mailgun: {
    logo: mailgunLogo,
    accent: '#C02126',
    blurb: () => __('Mailgun API')
  },
  sparkpost: {
    logo: sparkpostLogo,
    accent: '#FA6423',
    blurb: () => __('SparkPost (Bird) API')
  },
  microsoft365: {
    logo: microsoft365Logo,
    accent: '#0F6CBD',
    blurb: () => __('Microsoft 365 / Outlook')
  }
}

/** Resolves a provider key to its display visual (logo/initial/accent/blurb), falling back to a brand-blue tile for unknown providers. */
export function getProviderVisual(key: string, label?: string): ProviderVisual {
  const entry = providers[key]
  const displayLabel = label ?? key
  const initial = displayLabel.charAt(0).toUpperCase() || '?'

  if (entry) {
    return {
      logo: entry.logo,
      initial,
      accent: entry.accent,
      blurb: entry.blurb()
    }
  }

  return {
    logo: null,
    initial,
    accent: PRIMARY_SEED,
    blurb: __('Custom mail provider')
  }
}
