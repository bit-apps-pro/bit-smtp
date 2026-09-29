import { __ } from '@common/helpers/i18nwrap'

/** User-facing copy for one status/class value: a label, a one-line hint and optional depth. */
export interface GlossaryEntry {
  label: string
  hint: string
  example?: string
  next?: string
}

/** A failure-class entry, flagged with whether the retry engine may resend it. */
export interface FailureClassEntry extends GlossaryEntry {
  retryable: boolean
}

/** Backend `FailureCategory` values (excluding `ok`), in severity-of-fix order. */
export const FAILURE_CLASS_KEYS = [
  'transient',
  'rate_limited',
  'auth',
  'invalid_recipient',
  'permanent'
] as const

/** Backend `DeliveryStatus` values plus the frontend-only `not_tracked` (no delivery tracking). */
export const DELIVERY_STATUS_KEYS = [
  'delivered',
  'accepted',
  'deferred',
  'bounced',
  'spam',
  'blocked',
  'pending',
  'unknown',
  'not_tracked'
] as const

/** Per-message send outcomes shown in the Logs "Result" column. */
export const SEND_RESULT_KEYS = ['sent', 'failed', 'failover_recovered'] as const

/** Backend `HealthStatus` values. */
export const HEALTH_STATUS_KEYS = ['healthy', 'degraded', 'unhealthy', 'unknown'] as const

export type FailureClass = (typeof FAILURE_CLASS_KEYS)[number]
export type DeliveryStatusKey = (typeof DELIVERY_STATUS_KEYS)[number]
export type SendResult = (typeof SEND_RESULT_KEYS)[number]
export type HealthStatusKey = (typeof HEALTH_STATUS_KEYS)[number]

export const FAILURE_CLASSES: Readonly<Record<FailureClass, FailureClassEntry>> = {
  transient: {
    label: __('Temporary server or network problem'),
    hint: __('The mail server was unreachable or asked us to try later. Usually fixes itself.'),
    example: __(
      'Your SMTP host times out during maintenance, or Gmail replies "421 try again later" (greylisting).'
    ),
    next: __(
      "Retried automatically. If it persists, check the host/port and your server's outbound firewall."
    ),
    retryable: true
  },
  rate_limited: {
    label: __('Sending limit reached'),
    hint: __('Your provider is throttling you for sending too much, too fast.'),
    example: __(
      'A WooCommerce sale sends 800 receipts in a minute and Brevo answers "429 Too Many Requests".'
    ),
    next: __(
      "Retried after a pause. If it's frequent, raise your plan's limit or add a backup connection."
    ),
    retryable: true
  },
  auth: {
    label: __('Login or API key rejected'),
    hint: __("The provider refused your credentials. Retrying won't help until you fix them."),
    example: __(
      'Someone rotated the SendGrid API key, or the Gmail app password was revoked ("535 authentication failed").'
    ),
    next: __(
      'Not retried. The connection is marked Unhealthy immediately. Update credentials or reconnect OAuth.'
    ),
    retryable: false
  },
  invalid_recipient: {
    label: __("Recipient address doesn't exist"),
    hint: __("The receiving server says this mailbox isn't there. Failover is skipped too."),
    example: __('A customer typed jon@gmial.com, and the server replies "550 user unknown".'),
    next: __('Not retried and no failover. Correct the address, then use Edit and resend.'),
    retryable: false
  },
  permanent: {
    label: __('Rejected by provider'),
    hint: __('The message was refused for policy, content or reputation reasons.'),
    example: __(
      'Your sending domain is on a blocklist, or the provider flags the content as spam ("554 policy rejection").'
    ),
    next: __(
      "Not retried. Check SPF/DKIM/DMARC, the from-domain verification, and the provider's dashboard."
    ),
    retryable: false
  }
}

export const DELIVERY_STATUSES: Readonly<Record<DeliveryStatusKey, GlossaryEntry>> = {
  delivered: {
    label: __('Delivered'),
    hint: __("The recipient's mail server confirmed it received the message.")
  },
  accepted: {
    label: __('Accepted by provider'),
    hint: __('Your provider took the message and is delivering it. No final report yet.')
  },
  deferred: {
    label: __('Deferred'),
    hint: __("The recipient's server asked to try later. The provider is still retrying."),
    next: __(
      "Usually clears on its own. If it stays deferred for hours, check the provider's dashboard."
    )
  },
  bounced: {
    label: __('Bounced'),
    hint: __("The recipient's server rejected the message permanently."),
    example: __("The mailbox doesn't exist or is full."),
    next: __('Check the recipient address, then use Edit and resend.')
  },
  spam: {
    label: __('Marked as spam'),
    hint: __('The recipient or their mail system reported this message as spam.'),
    next: __('Make sure recipients expect your emails, and check SPF/DKIM/DMARC.')
  },
  blocked: {
    label: __('Blocked'),
    hint: __(
      'The provider refused to send to this address, usually because it bounced or complained before.'
    ),
    next: __("If the address is valid, remove it from the provider's suppression list.")
  },
  pending: {
    label: __('Awaiting report'),
    hint: __("Delivery tracking is on, but the provider hasn't reported on this message yet.")
  },
  unknown: {
    label: __('Status unavailable'),
    hint: __("The provider didn't return a recognizable delivery status."),
    next: __("Check the message in your provider's dashboard.")
  },
  not_tracked: {
    label: __('Not tracked'),
    hint: __("This connection doesn't report delivery, so only the send result is known."),
    next: __(
      'Use a provider with delivery webhooks (for example Brevo, Postmark or SES) to see inbox delivery.'
    )
  }
}

export const SEND_RESULTS: Readonly<Record<SendResult, GlossaryEntry>> = {
  sent: {
    label: __('Sent'),
    hint: __('Your mail provider accepted the message for delivery.')
  },
  failed: {
    label: __('Failed'),
    hint: __('Every connection that was tried refused the message.'),
    next: __('Open the log to see the error and failure type, fix the cause, then resend.')
  },
  failover_recovered: {
    label: __('Sent via failover'),
    hint: __('An earlier connection failed, and a backup connection sent the message.'),
    next: __('Check the connection that failed on the Connections page.')
  }
}

export const HEALTH_STATUSES: Readonly<Record<HealthStatusKey, GlossaryEntry>> = {
  healthy: {
    label: __('Healthy'),
    hint: __('Last send or check succeeded.')
  },
  degraded: {
    label: __('Degraded'),
    hint: __('A recent send or check failed. Sending keeps being retried.'),
    next: __("If it doesn't recover, check connection health and review the last error.")
  },
  unhealthy: {
    label: __('Unhealthy'),
    hint: __('Failing repeatedly or credentials rejected. A fix is required.'),
    next: __(
      'Review the last error, update credentials or reconnect OAuth, then check connection health.'
    )
  },
  unknown: {
    label: __('Not checked yet'),
    hint: __('No send or health check has run for this connection yet.'),
    next: __('Check connection health or send a test email.')
  }
}

/** Looks up `key` in `map`, or builds a fallback entry labelled with the raw key (or "Unknown" when empty). */
function lookup<E extends GlossaryEntry>(
  map: Readonly<Record<string, E>>,
  key: string | null | undefined,
  fallback: (label: string) => E
): E {
  if (key && Object.hasOwn(map, key)) return map[key]
  return fallback(key || __('Unknown'))
}

/** Glossary entry for a failure class; unknown keys get a non-retryable, raw-key-labelled entry. */
export function failureClassEntry(key: string | null | undefined): FailureClassEntry {
  return lookup(FAILURE_CLASSES, key, label => ({
    label,
    hint: __('This failure type is not recognized.'),
    retryable: false
  }))
}

/** Glossary entry for a delivery status; unknown keys get a raw-key-labelled entry. */
export function deliveryStatusEntry(key: string | null | undefined): GlossaryEntry {
  return lookup(DELIVERY_STATUSES, key, label => ({
    label,
    hint: __('This delivery status is not recognized.')
  }))
}

/** Glossary entry for a send result; unknown keys get a raw-key-labelled entry. */
export function sendResultEntry(key: string | null | undefined): GlossaryEntry {
  return lookup(SEND_RESULTS, key, label => ({
    label,
    hint: __('This send result is not recognized.')
  }))
}

/** Glossary entry for a connection health status; unknown keys fall back to "Not checked yet". */
export function healthStatusEntry(key: string | null | undefined): GlossaryEntry {
  return lookup(HEALTH_STATUSES, key, () => HEALTH_STATUSES.unknown)
}
