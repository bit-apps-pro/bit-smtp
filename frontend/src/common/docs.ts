/** Every plugin and help URL the admin UI links to, kept in one place so links stay auditable. */
export const DOCS = {
  bitApps: 'https://bitapps.pro',
  privacyPolicy: 'https://bitapps.pro/privacy-policy/',
  termsOfService: 'https://bitapps.pro/terms-of-service/',
  pluginReviews: 'https://wordpress.org/support/plugin/bit-smtp/reviews/'
} as const

export type DocKey = keyof typeof DOCS
