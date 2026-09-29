import { __, sprintf } from '@common/helpers/i18nwrap'

/** Confirmation copy for a destructive or outward-facing log action. */
export interface ConfirmCopy {
  title: string
  description: string
  okText: string
}

/** Picks the singular or plural translated string for `count`, interpolating the count into the plural. */
function byCount(count: number, one: string, other: string): string {
  return count === 1 ? one : sprintf(other, count)
}

/** Confirmation copy for resending `count` logged emails exactly as originally sent. */
export function resendConfirmCopy(count: number): ConfirmCopy {
  return {
    title: byCount(count, __('Resend this email?'), __('Resend %d emails?')),
    description:
      count === 1
        ? __('The recipient gets the message again, exactly as originally sent.')
        : __('Each recipient gets the message again, exactly as originally sent.'),
    okText: byCount(count, __('Resend email'), __('Resend %d emails'))
  }
}

/** Confirmation copy for permanently deleting `count` log entries. */
export function deleteConfirmCopy(count: number): ConfirmCopy {
  return {
    title: byCount(count, __('Delete this log entry?'), __('Delete %d log entries?')),
    description: __("This can't be undone."),
    okText: __('Delete')
  }
}

/** Success toast headline after `count` logged emails were resent. */
export function resendSuccessMessage(count: number): string {
  return byCount(count, __('Email resent.'), __('%d emails resent.'))
}

/** Success toast headline after `count` log entries were deleted. */
export function deleteSuccessMessage(count: number): string {
  return byCount(count, __('Log entry deleted.'), __('%d log entries deleted.'))
}

/** Tooltip for a non-zero automatic retry count. */
export function retriedMessage(count: number): string {
  return byCount(count, __('Retried automatically once'), __('Retried automatically %d times'))
}
