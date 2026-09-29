import { __, sprintf } from '@common/helpers/i18nwrap'

interface SignInLabels {
  /** Who the user signs in with, e.g. "Google". */
  brand: string
  signIn: string
  saveAndSignIn: string
}

/** Button and message wording for a provider's sign-in (Google, Microsoft, or a neutral fallback). */
export function signInLabels(provider: string): SignInLabels {
  switch (provider) {
    case 'gmail':
      return {
        brand: __('Google'),
        signIn: __('Sign in with Google'),
        saveAndSignIn: __('Save and sign in with Google')
      }
    case 'microsoft365':
      return {
        brand: __('Microsoft'),
        signIn: __('Sign in with Microsoft'),
        saveAndSignIn: __('Save and sign in with Microsoft')
      }
    default:
      return { brand: __('The provider'), signIn: __('Sign in'), saveAndSignIn: __('Save and sign in') }
  }
}

/** Plain-language "what happened, what to do" message for a backend OAuthFailureReason code. */
export function oauthFailureMessage(reason: string | undefined, brand: string): string {
  switch (reason) {
    case 'access_denied':
      return sprintf(
        __("%s didn't allow access. Sign in again and approve every permission it asks for."),
        brand
      )
    case 'invalid_client':
      return sprintf(
        __('%s rejected the Client ID or Client Secret. Check both, then sign in again.'),
        brand
      )
    case 'redirect_uri_mismatch':
      return __(
        "The Redirect URI in your app doesn't match. Copy the Redirect URI shown here into your app, then sign in again."
      )
    case 'invalid_grant':
    case 'invalid_state':
      return __('The sign-in took too long or was already used. Please sign in again.')
    case 'connection_not_found':
      return __('This connection no longer exists. Save it again, then sign in.')
    case 'token_exchange_failed':
      return sprintf(
        __("%s didn't finish the sign-in. Check your Client ID and Client Secret, then try again."),
        brand
      )
    case 'save_failed':
      return __("You signed in, but the account couldn't be saved. Please sign in again.")
    default:
      return __("Sign-in didn't finish. Please try again.")
  }
}
