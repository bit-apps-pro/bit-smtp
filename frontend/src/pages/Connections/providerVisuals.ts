import amazonSesLogo from '@resource/img/providers/amazon_ses.svg'
import gmailLogo from '@resource/img/providers/gmail.svg'
import otherSmtpLogo from '@resource/img/providers/other_smtp.svg'
import sendgridLogo from '@resource/img/providers/sendgrid.svg'

export interface ProviderVisual {
  logo: string | null
  initial: string
  accent: string
  blurb: string
}

interface ProviderEntry {
  logo: string
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
