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
    hint: __('The mail server was busy or unreachable. This usually fixes itself.'),
    example: __('Your email host is down for a few minutes of maintenance.'),
    next: __('We try again automatically. If it keeps happening, check your host and port.'),
    retryable: true
  },
  rate_limited: {
    label: __('Sending limit reached'),
    hint: __('You sent too many emails too quickly for your plan.'),
    example: __('A big sale sends hundreds of order emails in one minute.'),
    next: __(
      'We try again after a short wait. If it happens often, upgrade your plan or add a backup connection.'
    ),
    retryable: true
  },
  auth: {
    label: __('Login or API key rejected'),
    hint: __('Your email service did not accept your login details.'),
    example: __('Someone changed or deleted the API key or password.'),
    next: __("Trying again won't help. Update the login details or reconnect your account."),
    retryable: false
  },
  invalid_recipient: {
    label: __("Recipient address doesn't exist"),
    hint: __('The email address you sent to is not a real mailbox.'),
    example: __('A customer typed jon@gmial.com instead of jon@gmail.com.'),
    next: __('Fix the address, then resend the email from its log.'),
    retryable: false
  },
  permanent: {
    label: __('Rejected by provider'),
    hint: __('Your email service refused this email and will not send it.'),
    example: __('Your domain is not verified, or the email looks like spam.'),
    next: __("Trying again won't help. Check your email service's dashboard for the reason."),
    retryable: false
  }
}

export const DELIVERY_STATUSES: Readonly<Record<DeliveryStatusKey, GlossaryEntry>> = {
  delivered: {
    label: __('Delivered'),
    hint: __("Reached the recipient's mail server.")
  },
  accepted: {
    label: __('Accepted by provider'),
    hint: __('Your email service has it and is delivering it.')
  },
  deferred: {
    label: __('Deferred'),
    hint: __("Delayed by the recipient's server. Your email service keeps trying."),
    next: __('This usually clears up on its own within a few hours.')
  },
  bounced: {
    label: __('Bounced'),
    hint: __("The recipient's server refused it."),
    example: __("The mailbox doesn't exist or is full."),
    next: __('Check the address, then resend the email from its log.')
  },
  spam: {
    label: __('Marked as spam'),
    hint: __('The recipient reported it as spam.'),
    next: __('Only email people who expect to hear from you.')
  },
  blocked: {
    label: __('Blocked'),
    hint: __('Not sent, because this address failed before.'),
    next: __("If the address is correct, remove it from your email service's blocked list.")
  },
  pending: {
    label: __('Awaiting report'),
    hint: __('No update from your email service yet.')
  },
  unknown: {
    label: __('Status unavailable'),
    hint: __("We couldn't read the status your email service sent."),
    next: __("Check this email in your email service's dashboard.")
  },
  not_tracked: {
    label: __('Not tracked'),
    hint: __("This connection can't report inbox delivery."),
    next: __('To see inbox delivery, use a service like Brevo, Postmark or Amazon SES.')
  }
}

export const SEND_RESULTS: Readonly<Record<SendResult, GlossaryEntry>> = {
  sent: {
    label: __('Sent'),
    hint: __('Your email service accepted the email.')
  },
  failed: {
    label: __('Failed'),
    hint: __('The email could not be sent through any of your connections.'),
    next: __('Open the log to see why, fix the problem, then resend.')
  },
  failover_recovered: {
    label: __('Sent via failover'),
    hint: __('Your primary connection failed, so a backup sent the email.'),
    next: __('Check the connection that failed on the Connections page.')
  }
}

export const HEALTH_STATUSES: Readonly<Record<HealthStatusKey, GlossaryEntry>> = {
  healthy: {
    label: __('Healthy'),
    hint: __('Working normally.')
  },
  degraded: {
    label: __('Degraded'),
    hint: __('Some recent emails failed. We keep trying.'),
    next: __("If it doesn't recover soon, look at the last error.")
  },
  unhealthy: {
    label: __('Unhealthy'),
    hint: __('Emails keep failing on this connection. It needs your attention.'),
    next: __('Look at the last error, then update the login details or reconnect.')
  },
  unknown: {
    label: __('Not checked yet'),
    hint: __("We haven't checked this connection yet."),
    next: __('Click “Check connection health” or send a test email.')
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
