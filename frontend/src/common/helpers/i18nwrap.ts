/* eslint-disable @typescript-eslint/no-explicit-any */

/* eslint-disable no-underscore-dangle */

/* eslint-disable no-undef */
// eslint-disable-next-line import/no-extraneous-dependencies
import { __ as i18n_, sprintf as i18nsprintf } from '@wordpress/i18n'

/** Translate text, preferring the bitapp translations cache, then wp-i18n, then the raw string. */
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

/** sprintf-style interpolation with typeof guard to prevent ReferenceError when wp-i18n is unavailable. */
const sprintf = (text: string, ...vars: any) => {
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
