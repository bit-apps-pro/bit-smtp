/* eslint-disable @typescript-eslint/no-explicit-any */

/* eslint-disable no-underscore-dangle */

/* eslint-disable no-undef */
// eslint-disable-next-line import/no-extraneous-dependencies
import { _n as i18nN, __ as i18n_, sprintf as i18nsprintf } from '@wordpress/i18n'

/** The bitapp-cached translation for a string, or the raw string when wp is loaded without i18n; else undefined. */
const localTranslation = (text: string): string | undefined => {
  if (typeof bitapp !== 'undefined' && bitapp?.translations && bitapp.translations[text]) {
    return bitapp?.translations[text]
  }
  if (typeof wp !== 'undefined' && !wp?.i18n) {
    return text
  }
  return undefined
}

/** Translate text, preferring the bitapp translations cache, then wp-i18n, then the raw string. */
// eslint-disable-next-line @typescript-eslint/naming-convention
const __ = (text: string, domain = 'bitapp') => localTranslation(text) ?? i18n_(text, domain)

/** Translate a singular/plural pair for a count, with the same cache and wp-i18n guards as `__`. */
// eslint-disable-next-line @typescript-eslint/naming-convention
const _n = (single: string, plural: string, count: number, domain = 'bitapp') =>
  localTranslation(count === 1 ? single : plural) ?? i18nN(single, plural, count, domain)

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
  return i18nsprintf(text, ...vars)
}

export { __, _n, sprintf }
