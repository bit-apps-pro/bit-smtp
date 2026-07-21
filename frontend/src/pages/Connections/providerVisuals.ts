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
  // Real brand marks (Simple Icons / official assets), brand-tinted. `null` when no verified
  // logo exists for the provider, so the UI renders a letter tile instead of a fabricated mark.
  logo: string | null
  accent: string
  blurb: string
}

const providers: Record<string, ProviderEntry> = {
  other_smtp: {
    logo: otherSmtpLogo,
    accent: '#64748b',
    blurb: 'Any SMTP server'
  },
  sendgrid: {
    logo: sendgridLogo,
    accent: '#1A82E2',
    blurb: 'SendGrid API'
  },
  gmail: {
    logo: gmailLogo,
    accent: '#EA4335',
    blurb: 'Gmail / Google Workspace'
  },
  amazon_ses: {
    logo: amazonSesLogo,
    accent: '#FF9900',
    blurb: 'Amazon SES API'
  },
  postmark: {
    // No verified brand SVG/PNG available — letter tile (never a fabricated logo).
    logo: null,
    accent: '#E8912B',
    blurb: 'Postmark API'
  },
  brevo: {
    logo: brevoLogo,
    accent: '#0B996E',
    blurb: 'Brevo (Sendinblue) API'
  },
  resend: {
    logo: resendLogo,
    accent: '#0A0A0A',
    blurb: 'Resend API'
  },
  mailjet: {
    logo: mailjetLogo,
    accent: '#F5A623',
    blurb: 'Mailjet API'
  },
  zeptomail: {
    // Zoho parent-brand mark (ZeptoMail is a Zoho product; no standalone brand icon exists).
    logo: zeptomailLogo,
    accent: '#E42527',
    blurb: 'Zoho ZeptoMail API'
  },
  mailgun: {
    logo: mailgunLogo,
    accent: '#C02126',
    blurb: 'Mailgun API'
  },
  sparkpost: {
    logo: sparkpostLogo,
    accent: '#FA6423',
    blurb: 'SparkPost (Bird) API'
  },
  microsoft365: {
    logo: microsoft365Logo,
    accent: '#0F6CBD',
    blurb: 'Microsoft 365 / Outlook'
  }
}

export function getProviderVisual(key: string, label?: string): ProviderVisual {
  const entry = providers[key]
  const displayLabel = label ?? key
  const initial = displayLabel.charAt(0).toUpperCase() || '?'

  if (entry) {
    return {
      logo: entry.logo,
      initial,
      accent: entry.accent,
      blurb: entry.blurb
    }
  }

  return {
    logo: null,
    initial,
    accent: '#4f46e5',
    blurb: 'Custom mail provider'
  }
}
