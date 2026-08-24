/* eslint-disable @typescript-eslint/no-explicit-any */

/* eslint-disable no-underscore-dangle */

/* eslint-disable no-undef */
// eslint-disable-next-line import/no-extraneous-dependencies
import { __ as i18n_, sprintf as i18nsprintf } from '@wordpress/i18n'

// declare var bitapp: any

// eslint-disable-next-line @typescript-eslint/naming-convention
const __ = (text: string, domain = 'bitapp') => {
  if (typeof bitapp !== 'undefined' && bitapp?.translations && bitapp.translations[text]) {
    return bitapp?.translations[text]
  }
  if (typeof wp !== 'undefined' && !wp?.i18n) {
    return text
  }
  return i18n_(text, domain)
}

const sprintf = (text: string, ...vars: any) => {
  // Guard `wp` with typeof like __ above: a bare `wp?.i18n` throws ReferenceError when the global is
  // absent (test env, or a page where wp-i18n isn't enqueued) instead of falling back.
  if (typeof wp === 'undefined' || !wp?.i18n) {
    const matches: any = text.match(/%[s d u c o x X bg G e E f F]/g)
    let str = text
    vars.map((val: any, idx: number) => {
      str = str.replace(matches[idx], val)
    })
    return str
  }
  return i18nsprintf(text, vars)
}

export { __, sprintf }
