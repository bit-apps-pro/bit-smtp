import { MASK_SENTINEL } from '@pages/Connections/types'

export const EMAIL_PATTERN = /^[^\s@]+@[^\s@]+\.[^\s@]+$/

const SIGNING_SECRET_PATTERN = /^whsec_[A-Za-z0-9_-]{32,128}$/
const TELEGRAM_BOT_TOKEN_PATTERN = /^\d{6,20}:[A-Za-z0-9_-]{20,}$/
const TELEGRAM_CHAT_ID_PATTERN = /^-?\d{1,20}$/

/** Generate a webhook signing secret in the `whsec_...` shape the backend expects. */
export function generateSigningSecret(): string {
  const bytes = new Uint8Array(32)
  globalThis.crypto.getRandomValues(bytes)

  return `whsec_${Array.from(bytes, byte => byte.toString(16).padStart(2, '0')).join('')}`
}

/** True when `value` matches the webhook signing-secret shape, or is the already-saved mask sentinel. */
export function isValidSigningSecret(value: string): boolean {
  return value === MASK_SENTINEL || SIGNING_SECRET_PATTERN.test(value)
}

/** Strict webhook-URL gauntlet (https, exact host, no port/creds/query/fragment, non-empty path prefix). Checks literal host:path before URL parsing to catch ports URL() normalizes away. */
function isStrictWebhookUrl(value: string, hosts: string[], pathPrefix: string): boolean {
  if (value.includes('?') || value.includes('#')) {
    return false
  }
  if (!hosts.some(host => value.startsWith(`https://${host}${pathPrefix}`))) {
    return false
  }

  try {
    const url = new URL(value)
    return (
      url.protocol === 'https:' &&
      hosts.includes(url.hostname) &&
      url.port === '' &&
      url.username === '' &&
      url.password === '' &&
      url.search === '' &&
      url.hash === '' &&
      url.pathname.startsWith(pathPrefix) &&
      url.pathname.length > pathPrefix.length
    )
  } catch {
    return false
  }
}

/** True when `value` parses as an absolute http(s) URL. */
export function isHttpUrl(value: string): boolean {
  try {
    const { protocol } = new URL(value)
    return protocol === 'http:' || protocol === 'https:'
  } catch {
    return false
  }
}

/** Validate a Slack incoming-webhook URL, matching the backend's exact acceptance rules. */
export function isSlackIncomingWebhookUrl(value: string): boolean {
  return isStrictWebhookUrl(value, ['hooks.slack.com'], '/services/')
}

/** Validate a Discord webhook URL, matching the backend's exact acceptance rules. */
export function isDiscordWebhookUrl(value: string): boolean {
  return isStrictWebhookUrl(value, ['discord.com', 'discordapp.com'], '/api/webhooks/')
}

/** Validate a Telegram bot token shape (or an already-masked saved value). */
export function isTelegramBotToken(value: string): boolean {
  return value === MASK_SENTINEL || TELEGRAM_BOT_TOKEN_PATTERN.test(value)
}

/** Validate a Telegram chat ID shape (signed integer, matching the backend's accepted range). */
export function isValidTelegramChatId(value: string): boolean {
  return TELEGRAM_CHAT_ID_PATTERN.test(value)
}

/** Generate a client-side channel id in the `ch_...` shape the backend accepts. */
export function newChannelId(): string {
  const bytes = new Uint8Array(6)
  globalThis.crypto.getRandomValues(bytes)

  return `ch_${Array.from(bytes, byte => byte.toString(16).padStart(2, '0')).join('')}`
}

/** Default name for a newly added channel, deduped against same-type siblings so two never both read "Slack". */
export function defaultChannelName(label: string, sameType: { name: string }[]): string {
  let channelName = label
  let suffix = 2
  const existingNames = new Set(sameType.map(c => c.name))
  while (existingNames.has(channelName)) {
    channelName = `${label} ${suffix}`
    suffix += 1
  }
  return channelName
}
