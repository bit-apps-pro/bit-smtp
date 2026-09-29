// eslint-disable-next-line camelcase, @typescript-eslint/ban-ts-comment
// @ts-ignore
const serverVariables = typeof bit_smtp_ === 'undefined' ? {} : bit_smtp_ // eslint-disable-line camelcase,

/** Reads a boolean localized by wp_localize_script, which sends true as "1" and false as "". */
const isServerFlagSet = (value: unknown): boolean => value === true || value === '1'

const config = {
  PRODUCT_NAME: 'Bit SMTP',
  IS_DEV: true,
  AJAX_URL: serverVariables.ajaxURL || 'http://wordpress.test/wp-admin/admin-ajax.php',
  API_URL: serverVariables.apiURL || 'http://wordpress.test/wp-json/bit-smtp/v1',
  ROOT_URL: serverVariables.rootURL || 'http://wordpress.test',
  NONCE: serverVariables.nonce || '',
  ROUTE_PREFIX: serverVariables.routePrefix || 'bit_smtp_',
  AD_BUTTON: serverVariables.adButton,
  SEND_TO: serverVariables.sendTo || '',
  TEST_EMAIL_SENT: isServerFlagSet(serverVariables.testEmailSent),
  SUBJECT: 'This is a test email sent via Bit SMTP plugin'
}

export default config
