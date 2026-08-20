#!/usr/bin/env node
/* eslint-disable import/no-extraneous-dependencies */

// This file is from wp-i18n tools

/**
 * External dependencies
 */
const gettextParser = require('gettext-parser')
const { isEmpty } = require('lodash')
const fs = require('fs')

const TAB = '    '
const NEWLINE = '\n'
const args = process.argv.slice(2)
const fileHeader =
  ['<?php', '/* THIS IS A GENERATED FILE. DO NOT EDIT DIRECTLY. */', '$i18n_strings = array('].join(
    NEWLINE
  ) + NEWLINE

const fileFooter =
  NEWLINE + [');', '/* THIS IS THE END OF THE GENERATED FILE */'].join(NEWLINE) + NEWLINE

// Matches printf-style placeholders (e.g. %s, %d, %1$s) the way the WordPress
// Coding Standards i18n sniff does, so any flagged string gets a comment.
const PLACEHOLDER_REGEX = /%(?:\d+\$)?[+-]?(?:[ 0]|'.)?-?\d*(?:\.\d+)?[bcdeEfFgGosuxX]/g

/**
 * Escapes single quotes.
 *
 * @param {string} input The string to be escaped.
 * @return {string} The escaped string.
 */
function escapeSingleQuotes(input) {
  return input.replace(/'/g, "\\'")
}

/**
 * Builds the `translators:` comment WordPress requires immediately above any
 * translation call whose text contains placeholders; empty string when none.
 *
 * @param {string} text The translation source string(s) to scan.
 * @return {string} A comment line ending in a newline, or an empty string.
 */
function translatorsComment(text) {
  // Drop escaped percents first so a literal `%%` is never read as a placeholder.
  const placeholders = text.replace(/%%/g, '').match(PLACEHOLDER_REGEX)

  if (placeholders === null) {
    return ''
  }

  const unique = [...new Set(placeholders)].join(', ')

  return `${TAB}/* translators: ${unique}: value substituted at runtime. */${NEWLINE}`
}

/**
 * Converts a translation parsed from the POT file to lines of WP PHP.
 *
 * @param {Object} translation The translation to convert.
 * @param {string} textdomain The text domain to use in the WordPress translation function call.
 * @param {string} context The context for the translation.
 * @return {string} Lines of PHP that match the translation.
 */
function convertTranslationToPHP(translation, textdomain, context = '') {
  let php = ''

  // The format of gettext-js matches the terminology in gettext itself.
  let original = translation.msgid

  if (original !== '') {
    php += translatorsComment(`${translation.msgid} ${translation.msgid_plural || ''}`)
    original = escapeSingleQuotes(original)

    if (isEmpty(translation.msgid_plural)) {
      if (isEmpty(context)) {
        php += `${TAB}'${original}' => __('${original}', '${textdomain}')`
      } else {
        php += `${TAB}'${original}' => _x('${original}', '${translation.msgctxt}', '${textdomain}')`
      }
    } else {
      const plural = escapeSingleQuotes(translation.msgid_plural)

      if (isEmpty(context)) {
        php += `${TAB}'${original}' => _n_noop('${original}', '${plural}', '${textdomain}')`
      } else {
        php += `${TAB}'${original}' => _nx_noop('${original}',  '${plural}', '${translation.msgctxt}', '${textdomain}')`
      }
    }
  }

  return php
}

function convertPOTToPHP(potFile, phpFile, options) {
  const poContents = fs.readFileSync(potFile)
  const parsedPO = gettextParser.po.parse(poContents)

  let output = []

  for (const context of Object.keys(parsedPO.translations)) {
    const translations = parsedPO.translations[context]

    const newOutput = Object.values(translations)
      .map(translation => convertTranslationToPHP(translation, options.textdomain, context))
      .filter(php => php !== '')

    output = [...output, ...newOutput]
  }

  const fileOutput = fileHeader + output.join(`,${NEWLINE}${NEWLINE}`) + fileFooter

  fs.writeFileSync(phpFile, fileOutput)
}

convertPOTToPHP(args[0], args[1], {
  textdomain: args[2]
})
